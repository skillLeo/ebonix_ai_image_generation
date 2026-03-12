<?php
/*
 * File: king-include/king-pages/submitai.php
 */

if (!defined('QA_VERSION')) { header('Location: ../'); exit; }

set_time_limit(600);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');

require_once QA_INCLUDE_DIR . 'king-app/format.php';
require_once QA_INCLUDE_DIR . 'king-app/limits.php';
require_once QA_INCLUDE_DIR . 'king-db/selects.php';
require_once QA_INCLUDE_DIR . 'king-util/sort.php';
require_once QA_INCLUDE_DIR . 'king-db/metas.php';
require_once QA_INCLUDE_DIR . 'king-app/posts.php';

$in               = array();
$followpostid     = qa_get('follow');
$in['categoryid'] = qa_clicked('doask') ? qa_get_category_field_value('category') : qa_get('cat');
$userid           = qa_get_logged_in_userid();
$handle           = qa_get_logged_in_handle();

list($categories, $followanswer, $completetags) = qa_db_select_with_pending(
    qa_db_category_nav_selectspec($in['categoryid'], true),
    isset($followpostid) ? qa_db_full_post_selectspec($userid, $followpostid) : null,
    qa_db_popular_tags_selectspec(0, QA_DB_RETRIEVE_COMPLETE_TAGS)
);

if (!isset($categories[$in['categoryid']])) $in['categoryid'] = null;
if (@$followanswer['basetype'] != 'A')       $followanswer    = null;

$permiterror = qa_user_maximum_permit_error('permit_post_q', QA_LIMIT_QUESTIONS);

if ($permiterror && qa_clicked('doask')) {
    $errors              = array();
    $errors['permiterror'] = qa_lang_html('question/ask_limit');
    $response['status']  = 'error';
    $response['message'] = $errors;
    echo json_encode($response);
    exit;
}

if ($permiterror || !qa_opt('king_leo_enable')) {
    $qa_content = qa_content_prepare();
    switch ($permiterror) {
        case 'login':
            $econtent = qa_insert_login_links(qa_lang_html('question/ask_must_login'), qa_request(),
                isset($followpostid) ? array('follow' => $followpostid) : null);
            break;
        case 'confirm':
            $econtent = qa_insert_login_links(qa_lang_html('question/ask_must_confirm'), qa_request(),
                isset($followpostid) ? array('follow' => $followpostid) : null);
            break;
        case 'limit':
            $econtent = qa_lang_html('question/ask_limit');
            break;
        case 'membership':
            $econtent = qa_insert_login_links(qa_lang_html('misc/mem_message'));
            $qa_content['custom'] = '<div class="nopost"><i class="fa-solid fa-fingerprint fa-4x"></i><p>' . $econtent . '</p>'
                . '<a href="' . qa_path_html('membership') . '" class="meme-button">' . qa_lang_html('misc/see_plans') . '</a></div>';
            break;
        case 'approve':
            $econtent = qa_lang_html('question/ask_must_be_approved');
            break;
        default:
            $econtent = qa_lang_html('users/no_permission');
            break;
    }
    if (empty($qa_content['custom'])) {
        $qa_content['custom'] = '<div class="nopost"><i class="fa-solid fa-circle-user fa-4x"></i>' . $econtent . '</div>';
    }
    return $qa_content;
}

if (qa_clicked('doask')) {
    require_once QA_INCLUDE_DIR . 'king-app/post-create.php';
    require_once QA_INCLUDE_DIR . 'king-app/post-update.php';
    require_once QA_INCLUDE_DIR . 'king-util/string.php';

    $in['postid'] = qa_post_text('uniqueid');
    $post         = qa_db_select_with_pending(qa_db_full_post_selectspec($userid, $in['postid']));
    $categoryids  = array_keys(qa_category_path($categories, @$in['categoryid']));
    $userlevel    = qa_user_level_for_categories($categoryids);
    $in['nsfw']   = qa_post_text('nsfw');
    $in['prvt']   = qa_post_text('prvt');
    qa_get_post_content('editor', 'content', $in['editor'], $in['content'], $in['format'], $in['text']);

    $errors = array();
    if (!qa_check_form_security_code('ask', qa_post_text('code'))) {
        $errors['page'] = qa_lang_html('misc/form_security_again');
    } else {
        $filtermodules = qa_load_modules_with('filter', 'filter_question');
        foreach ($filtermodules as $filtermodule) {
            $oldin = $in;
            $filtermodule->filter_question($in, $errors, null);
            qa_update_post_text($in, $oldin);
        }
        // Clear ALL title errors — AI posts accept any title format
        unset($errors['title']);

        // Also ensure title is never empty — fall back to prompt text
        if (empty(trim((string)$in['title']))) {
            $in['title'] = !empty($input) ? $input : 'AI Generated Image';
        }

        if (qa_using_categories() && count($categories)
            && (!qa_opt('allow_no_category')) && !isset($in['categoryid'])) {
            $errors['categoryid'] = qa_lang_html('question/category_required');
        } elseif (qa_user_permit_error('permit_post_q', null, $userlevel)) {
            $errors['categoryid'] = qa_lang_html('question/category_ask_not_allowed');
        }
        if (empty($errors)) {
            $cookieid = isset($userid) ? qa_cookie_get() : qa_cookie_get_create();
            king_update_ai_post($in['postid'], $in['title'],
                isset($in['tags']) ? qa_tags_to_tagstring($in['tags']) : '',
                $in['nsfw'], 'I');
            $answers         = qa_post_get_question_answers($in['postid']);
            $commentsfollows = qa_post_get_question_commentsfollows($in['postid']);
            $closepost       = qa_post_get_question_closepost($in['postid']);
            if (qa_using_categories() && isset($in['categoryid'])) {
                qa_question_set_category($post, $in['categoryid'], $userid, $handle,
                    $cookieid, $answers, $commentsfollows, $closepost, false);
            }
            if (isset($in['prvt'])) qa_post_set_hidden($in['postid'], true, null);
            $response['status']   = 'success';
            $response['message']  = qa_lang_html('misc/published');
            $response['url']      = qa_q_request($in['postid'], $in['title']);
            $response['message2'] = qa_lang_html('misc/seep');
        } else {
            $response['status']  = 'error';
            $response['message'] = $errors;
        }
        echo json_encode($response);
        exit;
    }
}

if (qa_is_logged_in()
    && (qa_opt('ailimits') || qa_opt('ulimits'))
    && qa_get_logged_in_level() <= QA_USER_LEVEL_ADMIN
    && qa_opt('enable_membership')) {
    $qa_content = qa_content_prepare();
    $mp  = qa_db_usermeta_get($userid, 'membership_plan');
    $pl  = null;
    if ($mp) {
        $pl = (int)qa_opt('plan_' . $mp . '_lmt');
    } elseif (qa_opt('ulimits')) {
        $pl = (int)qa_opt('ulimit');
    }
    $alm = (int)qa_db_usermeta_get($userid, 'ailmt');
    if ($alm >= $pl) {
        $qa_content['custom'] = '<div class="nopost"><i class="fa-solid fa-circle-user fa-4x"></i>'
            . qa_lang('misc/nocredits')
            . '<p><a href="' . qa_path_html('membership') . '">' . qa_lang('misc/buycredits') . '</a></p></div>';
        return $qa_content;
    }
}

$qa_content          = qa_content_prepare(false,
    array_keys(qa_category_path($categories, @$in['categoryid'])));
$qa_content['title'] = qa_lang_html('main/image');
$qa_content['error'] = @$errors['page'];

$qa_content['head_lines'][] = '<script>if(typeof Dropzone!=="undefined"){Dropzone.autoDiscover=false;}</script>';

$captchareason = qa_user_captcha_reason();
$in['title']   = qa_get_post_title('title');
if (qa_using_tags()) $in['tags'] = qa_get_tags_field_value('tags');

$custom = qa_opt('show_custom_ask') ? trim(qa_opt('custom_ask')) : '';

// ═══════════════════════════════════════════════════════════════════════════
// MODEL DEFINITIONS
// i2i = true  → shows the image upload button in the toolbar
// selfie = true → shows selfie presets instead of standard size/style tabs
// ═══════════════════════════════════════════════════════════════════════════
$models = array(
    'sdn'            => array('enabled' => qa_opt('enable_sdn'),            'label' => qa_lang('misc/sdn'),            'i2i' => false, 'selfie' => false),
    'flux_pro'       => array('enabled' => qa_opt('enable_flux_pro'),       'label' => qa_lang('misc/flux_pro'),       'i2i' => false, 'selfie' => false),
    'sdream'         => array('enabled' => qa_opt('enable_sdream'),         'label' => qa_lang('misc/sdream'),         'i2i' => false, 'selfie' => false),
    'banana'         => array('enabled' => qa_opt('enable_banana'),         'label' => qa_lang('misc/banana'),         'i2i' => true,  'selfie' => false),
    'sd'             => array('enabled' => qa_opt('enable_sd'),             'label' => qa_lang('misc/sd'),             'i2i' => false, 'selfie' => false),
    'flux'           => array('enabled' => qa_opt('enable_flux'),           'label' => qa_lang('misc/flux'),           'i2i' => false, 'selfie' => false),
    'realxl'         => array('enabled' => qa_opt('enable_realxl'),         'label' => qa_lang('misc/realxl'),         'i2i' => false, 'selfie' => false),
    'imagen4'        => array('enabled' => qa_opt('enable_imagen4'),        'label' => qa_lang('misc/imagen4'),        'i2i' => false, 'selfie' => false),
    'fluxkon'        => array('enabled' => qa_opt('enable_fluxkon'),        'label' => qa_lang('misc/fluxkon'),        'i2i' => false, 'selfie' => false),
    'de'             => array('enabled' => qa_opt('enable_de'),             'label' => qa_lang('misc/de'),             'i2i' => false, 'selfie' => false),
    'de3'            => array('enabled' => qa_opt('enable_de3'),            'label' => qa_lang('misc/de3'),            'i2i' => false, 'selfie' => false),
    'decart_img'     => array('enabled' => qa_opt('enable_decart_img'),     'label' => qa_lang('misc/decart_img'),     'i2i' => false, 'selfie' => false),
    'luma_img'       => array('enabled' => qa_opt('enable_luma_img'),       'label' => qa_lang('misc/luma_img'),       'i2i' => false, 'selfie' => false),
    'fluxkon_selfie' => array(
        'enabled' => qa_opt('enable_fluxkon_selfie'),
        'label'   => (qa_lang('misc/fluxkon_selfie') ?: 'AI Selfie Looks'),
        'i2i'     => true,
        'selfie'  => true,
    ),
);

$enabled_models = array();
foreach ($models as $key => $data) {
    if (!empty($data['enabled'])) $enabled_models[$key] = $data;
}
if (empty($enabled_models)) $enabled_models = $models;

$i2i_models    = array_keys(array_filter($enabled_models, function ($m) { return !empty($m['i2i']); }));
$selfie_models = array_keys(array_filter($enabled_models, function ($m) { return !empty($m['selfie']); }));

reset($enabled_models);
$first_model_key   = key($enabled_models);
$first_model_label = $enabled_models[$first_model_key]['label'] ?? '';

// ═══════════════════════════════════════════════════════════════════════════
// BUILD SETTINGS PANEL HTML
// ═══════════════════════════════════════════════════════════════════════════
$context  = '';
$context .= '<div id="chclass" class="' . qa_html($first_model_key) . '">';
$context .= '<div class="kingai-ext">';
$context .= '<div class="ail-settings">';

// Model dropdown
$context .= '<div class="king-dropdownup custom-select hveo">';
$context .= '<div class="king-sbutton kings-button" id="aimodelbtn" data-toggle="dropdown"'
    . ' aria-expanded="false" role="button">' . qa_html($first_model_label) . '</div>';
$context .= '<div class="king-dropdownc king-dropleft aimodels">';
foreach ($enabled_models as $key => $data) {
    $checked = ($key === $first_model_key) ? 'checked' : '';
    $badge   = ($key === 'banana') ? ' ⭐' : (($key === 'fluxkon_selfie') ? ' 📸' : '');
    $context .= '<label class="cradio">'
        . '<input type="radio" name="aimodel" value="' . qa_html($key) . '" class="hide" '
        . $checked . ' onclick="updateModelLabel(this)">'
        . '<span>' . qa_html($data['label']) . $badge . '</span>'
        . '</label>';
}
$context .= '</div></div>'; // dropdown
$context .= '</div>'; // .ail-settings

// Standard size / style tabs
$context .= '<div id="desizes">';
$context .= '<ul class="nav nav-tabs" id="ssize">'
    . '<li class="active"><a href="#aisizes" data-toggle="tab">' . qa_lang('misc/aisizes') . '</a></li>'
    . '<li class="sdsize"><a href="#aistyles" data-toggle="tab">' . qa_lang('misc/ai_filter') . '</a></li>';
if (qa_opt('enprompt')) {
    $context .= '<li class="sdsize"><a href="#nprompt" data-toggle="tab">' . qa_lang('misc/ai_nprompt') . '</a></li>';
}
$context .= '</ul>';

$context .= '<div id="aisizes" role="tabpanel" class="tabcontent aistyles active">';
$sizes = array(
    'aisize9'  => array('1344x768',  'ailabel sdsize',         'widescreen', '16:9'),
    'aisize4'  => array('1152x896',  'ailabel sdsize',         'landscape',  '5:4'),
    'aisize10' => array('1792x1024', 'ailabel desize3',        'widescreen', '7:4'),
    'aisize1'  => array('512x512',   'ailabel desize',         'square',     '1:1'),
    'aisize3'  => array('1024x1024', 'ailabel',                'square',     '1:1'),
    'aisize11' => array('1024x1792', 'ailabel desize3',        'vertical',   '4:7'),
    'aisize8'  => array('896x1152',  'ailabel sdsize',         'portrait',   '4:5'),
    'aisize5'  => array('832x1216',  'ailabel sdsize aisize8', 'vertical',   '2:3'),
    'aisize7'  => array('768x1344',  'ailabel sdsize',         'long',       '9:16'),
);
foreach ($sizes as $id => $s) {
    $checked = ($id === 'aisize3') ? 'checked' : '';
    $sq = '';
    if ($id === 'aisize4')                          $sq = ' s2';
    elseif ($id === 'aisize11' || $id === 'aisize7') $sq = ' s5';
    elseif ($id === 'aisize8' || $id === 'aisize5')  $sq = ' s4';
    $context .= '<input type="radio" id="' . $id . '" name="aisize" value="' . $s[0] . '" class="hide" ' . $checked . '>';
    $context .= '<label for="' . $id . '" class="' . $s[1] . '" title="' . $s[0] . '" data-toggle="tooltip">'
        . '<i class="king-square' . $sq . '"></i> '
        . qa_lang('misc/' . $s[2]) . ' (' . $s[3] . ')</label>';
}
$context .= '</div>'; // #aisizes

if (qa_opt('enprompt')) {
    $context .= '<div id="nprompt" role="tabpanel" class="tabcontent aistyles">';
    $context .= '<textarea name="nprompt" id="n_prompt" rows="2" cols="40" class="king-form-tall-text"'
        . ' placeholder="' . qa_lang('misc/ai_nprompt') . '"></textarea>';
    $context .= '</div>';
}

$context .= '<div id="aistyles" role="tabpanel" class="tabcontent aistyles">';
foreach (array('none','3d-model','analog-film','anime','cinematic','comic-book','digital-art',
               'fantasy-art','isometric','line-art','low-poly','neon-punk','origami',
               'photographic','pixel-art') as $style) {
    $context .= '<input type="radio" id="aistyle_' . $style . '" name="aistyle" value="' . $style . '" class="hide">';
    $context .= '<label for="aistyle_' . $style . '" class="ailabel">' . $style . '</label>';
}
$context .= '</div>'; // #aistyles
$context .= '</div>'; // #desizes

// Selfie style presets
$selfie_presets = array(
    'selfie_luxury_editorial' => array('icon' => 'fa-crown',     'label' => 'Luxury Editorial', 'desc' => 'High-end magazine aesthetic'),
    'selfie_soft_glam'        => array('icon' => 'fa-heart',     'label' => 'Soft Glam',        'desc' => 'Natural glam beauty look'),
    'selfie_professional'     => array('icon' => 'fa-briefcase', 'label' => 'Professional',     'desc' => 'Corporate headshot quality'),
    'selfie_vacation'         => array('icon' => 'fa-sun',       'label' => 'Vacation',         'desc' => 'Golden hour travel vibes'),
    'selfie_afro_futurist'    => array('icon' => 'fa-star',      'label' => 'Afro-Futurist',    'desc' => 'Cultural sci-fi fusion'),
);

$context .= '<div id="selfie-presets-panel" style="display:none;">';
$context .= '<div class="selfie-presets-header"><i class="fa-solid fa-camera-retro"></i> Choose your look:</div>';
$context .= '<div class="selfie-presets-grid">';
$first_sp = true;
foreach ($selfie_presets as $spkey => $spdata) {
    $sid     = 'selfie_preset_' . $spkey;
    $checked = $first_sp ? 'checked' : '';
    $active  = $first_sp ? ' selfie-active' : '';
    $context .= '<label class="selfie-preset-item' . $active . '" for="' . $sid . '">';
    $context .= '<input type="radio" id="' . $sid . '" name="aistyle" value="' . $spkey . '" class="hide" ' . $checked . '>';
    $context .= '<i class="fa-solid ' . $spdata['icon'] . '"></i>';
    $context .= '<span class="selfie-preset-label">' . $spdata['label'] . '</span>';
    $context .= '<span class="selfie-preset-desc">' . $spdata['desc'] . '</span>';
    $context .= '</label>';
    $first_sp = false;
}
$context .= '</div></div>'; // selfie-presets-grid / selfie-presets-panel

$context .= '</div>'; // .kingai-ext
$context .= '</div>'; // #chclass

// Results area
$context .= '<div id="ai-results">' . king_ai_posts($userid, 'aimg') . '</div>';

$king_ajax_url = rtrim((string)qa_opt('site_url'), '/') . '/king-include/king-ajax.php';

// ═══════════════════════════════════════════════════════════════════════════
// LOGGED-IN UI
// ═══════════════════════════════════════════════════════════════════════════
if (qa_is_logged_in()) {
    $cont = '';

    if (qa_opt('king_leo_enable') && qa_opt('enable_aivideo')) {
        $cont .= '<ul class="king-nav-kingsub-list" id="nav-kingsub">';
        $cont .= '<li class="king-nav-kingsub-item"><a href="' . qa_path_html('submitai') . '" class="king-nav-kingsub-selected"><i class="fa-regular fa-image"></i> ' . qa_lang_html('misc/king_ai') . '</a></li>';
        $cont .= '<li class="king-nav-kingsub-item"><a href="' . qa_path_html('videoai') . '"><i class="fa-regular fa-circle-play"></i> ' . qa_lang_html('misc/king_aivid') . '</a></li>';
        $cont .= '</ul>';
    }

    $cont .= '<div class="kingai-box active">';
    $cont .= '<div class="king-form-tall-error" id="ai-error" style="display:none;"></div>';
    if ($custom) $cont .= '<div class="snote">' . $custom . '</div>';

    // ── Image preview chip (shows above prompt when an image is attached) ───
    $cont .= '<div id="ref-image-preview-wrap" style="display:none;">';
    $cont .= '<div class="ref-img-chip">';
    $cont .= '<img id="ref-image-thumb" src="" alt="preview">';
    $cont .= '<span id="ref-image-chipname"></span>';
    $cont .= '<button type="button" class="ref-img-chip-remove" onclick="clearRefImage()" title="Remove image">'
        . '<i class="fa-solid fa-xmark"></i></button>';
    $cont .= '</div>';
    $cont .= '</div>'; // #ref-image-preview-wrap

    // ── Prompt area ──────────────────────────────────────────────────────────
    $cont .= '<div class="kingai-input">';
    $cont .= '<textarea id="ai-box" class="aiinput" oninput="adjustHeight(this)"'
        . ' placeholder="' . qa_lang('misc/aiplace') . '"'
        . ' maxlength="600" autocomplete="off" style="height:44px;" rows="1"></textarea>';

    $cont .= '<div class="kingai-buttons">';

    // Hidden real file input — triggered by the attach button
    $cont .= '<input type="hidden" id="news_thumb" name="news_thumb" value="">';
    $cont .= '<input type="file" id="ref_image" name="ref_image"'
        . ' accept="image/jpeg,image/png,image/webp,image/gif"'
        . ' class="aiupload-file-hidden">';

    // Attach image button (clip icon, only visible for i2i models)
    $cont .= '<button type="button" id="ref-image-btn" style="display:none;"'
        . ' class="king-sbutton ai-attach-btn" onclick="document.getElementById(\'ref_image\').click()"'
        . ' data-toggle="tooltip" title="' . qa_lang('misc/attach_ref_image') . '" data-placement="top">'
        . '<i class="fa-solid fa-paperclip"></i>'
        . '</button>';

    // Prompter button
    if (qa_opt('eprompter')) {
        $showElement = qa_opt('oaprompter') ? (qa_get_logged_in_level() >= QA_USER_LEVEL_ADMIN) : true;
        if ($showElement) {
            $cont .= '<button type="button" id="prompter" onclick="aipromter(this)" class="king-sbutton ai-create promter"'
                . ' data-toggle="tooltip" title="' . qa_lang('misc/prompter') . '" data-placement="left">'
                . '<i class="fa-solid fa-feather"></i><div class="loader"></div></button>';
        }
    }

    // Settings toggle
    $cont .= '<div class="king-sbutton" onclick="toggleSwitcher(\'.kingai-box\', this)" role="button">'
        . '<i class="fa-solid fa-sliders"></i></div>';

    // Generate button
    $cont .= '<button type="button" id="ai-submit" class="ai-submit" onclick="return aigenerate(this);">'
        . '<span><i class="fa-solid fa-paper-plane"></i> ' . qa_lang('misc/generate') . '</span>'
        . '<div class="loader"></div></button>';

    $cont .= '</div></div>'; // .kingai-buttons / .kingai-input

    $cont .= $context;
    $cont .= '</div>'; // .kingai-box

    // ═══════════════════════════════════════════════════════════════════════
    // INLINE JS
    // ═══════════════════════════════════════════════════════════════════════
    $cont .= '<script>';
    $cont .= 'var EBONIX_I2I_MODELS    = ' . json_encode(array_values($i2i_models)) . ';';
    $cont .= 'var EBONIX_SELFIE_MODELS = ' . json_encode(array_values($selfie_models)) . ';';
    $cont .= 'var EBONIX_UPLOAD_URL    = ' . json_encode($king_ajax_url) . ';';
    $cont .= 'var ebonix_qa_root       = ' . json_encode(rtrim(qa_opt('site_url'), '/') . '/') . ';';

    $cont .= <<<'JS'

// ── updateModelLabel ─────────────────────────────────────────────────────────
function updateModelLabel(radioEl) {
    var btn = document.getElementById('aimodelbtn');
    if (btn) {
        var lbl = radioEl.closest('label');
        if (lbl) btn.textContent = (lbl.innerText || lbl.textContent || '').trim();
    }
    var ch = document.getElementById('chclass');
    if (ch) ch.className = radioEl.value;

    var aivsize = document.getElementById('aivsize');
    if (aivsize) aivsize.checked = true;
    var aivsizeb = document.getElementById('aivsizeb');
    if (aivsizeb) aivsizeb.textContent = '16:9';
    var firstTabLink = document.querySelector('#ssize li:first-child a');
    if (firstTabLink) firstTabLink.click();

    ebonixUpdateModelUI(radioEl.value);
}

// ── ebonixUpdateModelUI ──────────────────────────────────────────────────────
function ebonixUpdateModelUI(modelValue) {
    var attachBtn   = document.getElementById('ref-image-btn');
    var selfiePanel = document.getElementById('selfie-presets-panel');
    var desizes     = document.getElementById('desizes');
    var aiBox       = document.getElementById('ai-box');

    var supportsI2I = (EBONIX_I2I_MODELS.indexOf(modelValue) !== -1);
    var isSelfie    = (EBONIX_SELFIE_MODELS.indexOf(modelValue) !== -1);

    // Show/hide the paperclip attach button in the toolbar
    if (attachBtn) attachBtn.style.display = supportsI2I ? 'inline-flex' : 'none';

    // Selfie presets vs standard size/style tabs
    if (selfiePanel) selfiePanel.style.display = isSelfie ? 'block' : 'none';
    if (desizes)     desizes.style.display     = isSelfie ? 'none'  : 'block';

    // Prompt placeholder text
    if (aiBox) {
        aiBox.placeholder = isSelfie
            ? 'Describe any additional details (optional)\u2026'
            : 'JS_AIPLACE';
    }

    // Clear any attached image when switching to a non-i2i model
    if (!supportsI2I) clearRefImage();
}

// ── clearRefImage ────────────────────────────────────────────────────────────
function clearRefImage() {
    var fi = document.getElementById('ref_image');
    if (fi) fi.value = '';

    var nt = document.getElementById('news_thumb');
    if (nt) nt.value = '';

    var wrap = document.getElementById('ref-image-preview-wrap');
    if (wrap) wrap.style.display = 'none';

    var thumb = document.getElementById('ref-image-thumb');
    if (thumb) { thumb.src = ''; }

    var chip = document.getElementById('ref-image-chipname');
    if (chip) chip.textContent = '';

    // Reset attach button to default state
    var btn = document.getElementById('ref-image-btn');
    if (btn) btn.classList.remove('has-image');
}

// ── File input change: show preview chip ─────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {

    var fileInput = document.getElementById('ref_image');

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0];
            if (!file) { clearRefImage(); return; }

            // Show preview chip above prompt
            var reader = new FileReader();
            reader.onload = function (e) {
                var thumb = document.getElementById('ref-image-thumb');
                var chip  = document.getElementById('ref-image-chipname');
                var wrap  = document.getElementById('ref-image-preview-wrap');
                var btn   = document.getElementById('ref-image-btn');

                if (thumb) thumb.src = e.target.result;
                if (chip)  chip.textContent = file.name.length > 28
                    ? file.name.substring(0, 25) + '...'
                    : file.name;
                if (wrap) wrap.style.display = 'block';
                // Highlight the attach button to show image is loaded
                if (btn)  btn.classList.add('has-image');
            };
            reader.readAsDataURL(file);
        });
    }

    // Selfie preset active highlight
    document.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'aistyle') {
            document.querySelectorAll('.selfie-preset-item').forEach(function (el) {
                el.classList.remove('selfie-active');
            });
            var closest = e.target.closest('.selfie-preset-item');
            if (closest) closest.classList.add('selfie-active');
        }
    });

    // Wire model radio change
    document.querySelectorAll('input[name="aimodel"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (this.checked) ebonixUpdateModelUI(this.value);
        });
    });

    // Initial UI state for default model
    var def = document.querySelector('input[name="aimodel"]:checked');
    if (def) ebonixUpdateModelUI(def.value);

    // ── Fix 3: MutationObserver — force-load lazy images after AJAX inject ──
    var resultsEl = document.getElementById('ai-results');
    if (resultsEl) {
        var lazyObserver = new MutationObserver(function (mutations) {
            var added = false;
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes.length > 0) { added = true; break; }
            }
            if (!added) return;

            // Force data-src → src for all lazy images
            resultsEl.querySelectorAll('img[data-src]').forEach(function (img) {
                var ds = img.getAttribute('data-src');
                if (ds && (!img.src || img.src === window.location.href || img.src === '')) {
                    img.src = ds;
                }
            });
            resultsEl.querySelectorAll('img[data-lazy-src]').forEach(function (img) {
                var ds = img.getAttribute('data-lazy-src');
                if (ds && (!img.src || img.src === window.location.href || img.src === '')) {
                    img.src = ds;
                }
            });
            // Also fix broken images whose src is set but empty/placeholder
            resultsEl.querySelectorAll('img').forEach(function (img) {
                if (img.dataset.src && (!img.complete || img.naturalWidth === 0)) {
                    img.src = img.dataset.src;
                }
            });
            // Refresh global lazy-load library instances if present
            if (window.lazyLoadInstance && typeof window.lazyLoadInstance.update === 'function') {
                window.lazyLoadInstance.update();
            }
            if (typeof jQuery !== 'undefined') {
                try { jQuery(resultsEl).find('img.lazy,img.lazyload').trigger('appear'); } catch(e) {}
            }
        });
        lazyObserver.observe(resultsEl, { childList: true, subtree: true });
    }
});

// ── Reuse / Regenerate payload ────────────────────────────────────────────────
(function () {
    function kingGetReusePayload() {
        var raw = null;
        try { raw = sessionStorage.getItem('king_ai_reuse'); } catch (e) {}
        if (!raw) return null;
        try {
            var data = JSON.parse(raw);
            try { sessionStorage.removeItem('king_ai_reuse'); } catch (e) {}
            return data;
        } catch (e) { return null; }
    }
    function kingSetTextarea(id, val) {
        var el = document.getElementById(id);
        if (!el) return;
        el.value = val || '';
        if (typeof adjustHeight === 'function') { try { adjustHeight(el); } catch (e) {} }
    }
    function kingSelectRadio(name, value) {
        if (!value) return false;
        var input = document.querySelector('input[name="' + name + '"][value="' + CSS.escape(value) + '"]');
        if (!input) return false;
        input.checked = true;
        try { input.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
        return true;
    }
    function kingSetImageModel(model) {
        if (!model) return;
        var input = document.querySelector('input[name="aimodel"][value="' + CSS.escape(model) + '"]');
        if (!input) return;
        input.checked = true;
        try { input.click(); } catch (e) {}
        var btn = document.getElementById('aimodelbtn');
        if (btn) {
            var lbl = input.closest('label');
            if (lbl) { var t = (lbl.innerText || '').trim(); if (t) btn.textContent = t; }
        }
        var ch = document.getElementById('chclass');
        if (ch) ch.className = model;
    }
    document.addEventListener('DOMContentLoaded', function () {
        var payload = kingGetReusePayload();
        if (!payload || (payload.isVideo && parseInt(payload.isVideo, 10) === 1)) return;
        if (payload.prompt)  kingSetTextarea('ai-box', payload.prompt);
        if (payload.model)   kingSetImageModel(payload.model);
        if (payload.size)    kingSelectRadio('aisize',  payload.size);
        if (payload.style)   kingSelectRadio('aistyle', payload.style);
        if (payload.nprompt) kingSetTextarea('n_prompt', payload.nprompt);
        var box = document.getElementById('ai-box');
        if (box) { try { box.focus(); } catch (e) {} }
    });
}());

JS;

    $cont = str_replace('JS_AIPLACE', addslashes(qa_lang('misc/aiplace')), $cont);

    $cont .= '</script>';

    // ═══════════════════════════════════════════════════════════════════════
    // INLINE CSS
    // ═══════════════════════════════════════════════════════════════════════
    $cont .= '<style>

/* ── Hidden real file input ── */
.aiupload-file-hidden {
    position: absolute;
    left: -9999px;
    opacity: 0;
    width: 1px;
    height: 1px;
    pointer-events: none;
}

/* ── Attach button in toolbar ── */
.ai-attach-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: none;
    background: transparent;
    color: #888;
    font-size: 15px;
    cursor: pointer;
    transition: color 0.2s, background 0.2s;
    flex-shrink: 0;
}
.ai-attach-btn:hover { color: #7b61ff; background: rgba(123,97,255,0.1); }
.ai-attach-btn.has-image { color: #7b61ff; }
.ai-attach-btn.has-image i { color: #7b61ff; }

/* ── Image preview chip ── */
#ref-image-preview-wrap {
    padding: 6px 12px 0;
}
.ref-img-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(123,97,255,0.1);
    border: 1px solid rgba(123,97,255,0.3);
    border-radius: 10px;
    padding: 5px 10px 5px 6px;
    max-width: 100%;
    overflow: hidden;
}
.ref-img-chip img {
    width: 36px;
    height: 36px;
    object-fit: cover;
    border-radius: 7px;
    flex-shrink: 0;
    display: block;
}
#ref-image-chipname {
    font-size: 12px;
    color: #bbb;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 200px;
    flex: 1;
}
.ref-img-chip-remove {
    flex-shrink: 0;
    background: rgba(220,50,50,0.15);
    border: 1px solid rgba(220,50,50,0.3);
    border-radius: 50%;
    width: 20px;
    height: 20px;
    cursor: pointer;
    color: #ff6b6b;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    padding: 0;
    transition: background 0.2s;
    line-height: 1;
}
.ref-img-chip-remove:hover { background: rgba(220,50,50,0.35); }

/* ── Selfie presets ── */
#selfie-presets-panel { margin: 10px 0; }
.selfie-presets-header {
    font-size: 13px;
    font-weight: 600;
    color: #aaa;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.selfie-presets-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 8px;
}
@media (max-width: 600px) {
    .selfie-presets-grid { grid-template-columns: repeat(3, 1fr); }
}
.selfie-preset-item {
    cursor: pointer;
    border-radius: 10px;
    border: 2px solid transparent;
    background: rgba(255,255,255,0.04);
    padding: 12px 6px 10px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    user-select: none;
}
.selfie-preset-item:hover         { background: rgba(123,97,255,0.1); border-color: rgba(123,97,255,0.3); }
.selfie-preset-item.selfie-active { border-color: #7b61ff; background: rgba(123,97,255,0.15); }
.selfie-preset-item i     { font-size: 20px; color: #7b61ff; }
.selfie-preset-label      { font-size: 12px; font-weight: 600; color: #ddd; }
.selfie-preset-desc       { font-size: 10px; color: #888; line-height: 1.3; }

</style>';

    $qa_content['custom'] = $cont;

    // ── Form (publish sidebar) ────────────────────────────────────────────────
    $qa_content['form'] = array(
'tags'   => 'name="ask" method="post" action="' . qa_self_html() . '" id="ai-form" novalidate',
        'style'  => 'tall',
        'fields' => array(
            'close'    => array('type' => 'custom', 'html' => '<span onclick="aipublish(this)" class="aisclose"><i class="fa-solid fa-xmark"></i></span>'),
            'errorc'   => array('type' => 'custom', 'html' => '<div id="error-container"></div>'),
            'title'    => array(
                'label' => qa_lang_html('question/q_title_label'),
'tags'  => 'name="title" id="title" autocomplete="off"',                'value' => qa_html(@$in['title']),
                'error' => qa_html(@$errors['title']),
            ),
            'similar'  => array('type' => 'custom', 'html' => '<span id="similar"></span>'),
            'uniqueid' => array('label' => '', 'tags' => 'name="uniqueid" id="uniqueid" class="hide"'),
        ),
        'buttons' => array(
            'ask' => array(
                'tags'  => 'onclick="submitAiform(event);" id="submitButton"',
                'label' => qa_lang_html('question/ask_button'),
            ),
        ),
        'hidden' => array(
            'code'  => qa_get_form_security_code('ask'),
            'doask' => '1',
        ),
    );

    script_options($qa_content);
    if (!strlen($custom)) unset($qa_content['form']['fields']['custom']);

    if (qa_opt('do_ask_check_qs') || qa_opt('do_example_tags')) {
        $qa_content['script_rel'][] = 'king-content/king-ask.js?' . QA_VERSION;
        $qa_content['form']['fields']['title']['tags'] .= ' onchange="qa_title_change(this.value);"';
        if (strlen(@$in['title'])) {
            $qa_content['script_onloads'][] = 'qa_title_change(' . qa_js($in['title']) . ');';
        }
    }

    $qa_content['script_var']['leoai']           = $king_ajax_url;
    $qa_content['script_var']['ebonix_ajax_url'] = $king_ajax_url;
    $qa_content['script_var']['ebonix_qa_root']  = rtrim(qa_opt('site_url'), '/') . '/';

    if (isset($followanswer)) {
        $viewer = qa_load_viewer($followanswer['content'], $followanswer['format']);
        $field  = array(
            'type'  => 'static',
            'label' => qa_lang_html('question/ask_follow_from_a'),
            'value' => $viewer->get_html($followanswer['content'], $followanswer['format'],
                array('blockwordspreg' => qa_get_block_words_preg())),
        );
        qa_array_insert($qa_content['form']['fields'], 'title', array('follows' => $field));
    }

    if (qa_using_categories() && count($categories)) {
        $field = array(
            'label' => qa_lang_html('question/q_category_label'),
            'error' => qa_html(@$errors['categoryid']),
        );
        qa_set_up_category_field($qa_content, $field, 'category', $categories,
            $in['categoryid'], true, qa_opt('allow_no_sub_category'));
        if (!qa_opt('allow_no_category')) $field['options'][''] = '';
        qa_array_insert($qa_content['form']['fields'], 'similar', array('category' => $field));
    }

    if (qa_using_tags()) {
        $field = array('error' => qa_html(@$errors['tags']));
        qa_set_up_tag_field($qa_content, $field, 'tags',
            isset($in['tags']) ? $in['tags'] : array(), array(),
            qa_opt('do_complete_tags') ? array_keys($completetags) : array(),
            qa_opt('page_size_ask_tags'));
        qa_array_insert($qa_content['form']['fields'], null, array('tags' => $field));
    }

    if (qa_opt('enable_nsfw') || qa_opt('enable_pposts')) {
        $nsfw = ''; $prvt = '';
        if (qa_opt('enable_pposts')) {
            $prvt = '<input name="prvt" id="king_prvt" type="checkbox" class="hide" value="'
                . qa_html(@$in['prvt']) . '"><label for="king_prvt" class="king-nsfw">'
                . '<i class="fa-solid fa-user-ninja"></i> ' . qa_lang('misc/prvt') . '</label>';
        }
        if (qa_opt('enable_nsfw')) {
            $nsfw = '<input name="nsfw" id="king_nsfw" type="checkbox" value="'
                . qa_html(@$in['nsfw']) . '"><label for="king_nsfw" class="king-nsfw">'
                . qa_lang_html('misc/nsfw') . '</label>';
        }
        $field = array('type' => 'custom', 'html' => $prvt . $nsfw);
        qa_array_insert($qa_content['form']['fields'], null, array('nsfw' => $field));
    }

    if (!isset($userid)) qa_set_up_name_field($qa_content, $qa_content['form']['fields'], @$in['name']);

    if ($captchareason) {
        require_once QA_INCLUDE_DIR . 'king-app/captcha.php';
        qa_set_up_captcha_field($qa_content, $qa_content['form']['fields'],
            @$errors, qa_captcha_reason_note($captchareason));
    }

} else {
    $cont2  = '<div class="kingai-input">';
    $cont2 .= '<textarea id="ai-box" class="aiinput" data-toggle="modal" data-target="#loginmodal"'
        . ' placeholder="' . qa_lang('misc/aiplace') . '"'
        . ' maxlength="600" autocomplete="off" style="height:44px;" rows="1"></textarea>';
    $cont2 .= '<div class="kingai-buttons">';
    $cont2 .= '<div class="king-sbutton" data-toggle="modal" data-target="#loginmodal" role="button">'
        . '<i class="fa-solid fa-sliders"></i></div>';
    $cont2 .= '<button type="button" id="ai-submit" class="ai-submit"'
        . ' data-toggle="modal" data-target="#loginmodal">'
        . '<span><i class="fa-solid fa-paper-plane"></i> ' . qa_lang('misc/generate') . '</span>'
        . '<div class="loader"></div></button>';
    $cont2 .= '</div></div>';
    $qa_content['custom'] = $cont2;
}

$qa_content['class']   = ' ai-create';
$qa_content['focusid'] = 'ai-box';

return $qa_content;