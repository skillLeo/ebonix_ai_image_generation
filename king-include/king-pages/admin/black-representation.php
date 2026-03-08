<?php
/**
 * Admin Panel - Black Representation Settings
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../../');
    exit;
}

require_once QA_INCLUDE_DIR . 'king-app/admin.php';

if (qa_get_logged_in_level() < QA_USER_LEVEL_ADMIN) {
    qa_redirect('login');
}

// Handle form submission
if (qa_clicked('save_black_rep_settings')) {
    qa_opt('black_rep_enabled', qa_post_text('black_rep_enabled') ? '1' : '0');
    qa_opt('black_rep_skin_tone', qa_post_text('black_rep_skin_tone'));
    qa_opt('black_rep_hair_texture', qa_post_text('black_rep_hair_texture'));
    qa_opt('black_rep_face_features', qa_post_text('black_rep_face_features'));
    qa_opt('black_rep_age', qa_post_text('black_rep_age'));
    qa_opt('black_rep_gender', qa_post_text('black_rep_gender'));
    qa_opt('black_rep_makeup', qa_post_text('black_rep_makeup'));
    qa_opt('black_rep_lighting', qa_post_text('black_rep_lighting'));
    qa_opt('black_rep_prevent_whitewashing', qa_post_text('black_rep_prevent_whitewashing') ? '1' : '0');
    qa_opt('black_rep_keep_consistent', qa_post_text('black_rep_keep_consistent') ? '1' : '0');
    
    $saved_message = 'Black Representation Settings Saved Successfully!';
}

$qa_content = qa_content_prepare();
$qa_content['title'] = 'Black Representation Settings';
$qa_content['error'] = null;

if (isset($saved_message)) {
    $qa_content['success'] = $saved_message;
}

$qa_content['form'] = [
    'tags' => 'method="post" action="' . qa_self_html() . '"',
    'style' => 'wide',
    'fields' => [
        'enabled' => [
            'label' => 'Enable Black Representation Rules',
            'type' => 'checkbox',
            'value' => qa_opt('black_rep_enabled') == '1',
            'tags' => 'name="black_rep_enabled"',
            'note' => 'When enabled, all AI generations will apply representation rules',
        ],
        
        'header_core' => [
            'type' => 'static',
            'label' => '<h3>Core Features</h3>',
        ],
        
        'skin_tone' => [
            'label' => 'Default Skin Tone Range',
            'type' => 'select',
            'tags' => 'name="black_rep_skin_tone"',
            'value' => qa_opt('black_rep_skin_tone'),
            'options' => [
                '' => 'Not specified',
                'light_brown' => 'Light Brown',
                'medium_brown' => 'Medium Brown',
                'deep_brown' => 'Deep Brown',
                'dark_brown' => 'Dark Brown',
                'rich_ebony' => 'Rich Ebony',
                'diverse' => 'Full Range (Light → Deep)',
            ],
            'note' => 'Default skin tone for generated images',
        ],
        
        'hair_texture' => [
            'label' => 'Curl Pattern / Hair Texture',
            'type' => 'select',
            'tags' => 'name="black_rep_hair_texture"',
            'value' => qa_opt('black_rep_hair_texture'),
            'options' => [
                '' => 'Not specified',
                '3a' => 'Type 3A (Loose Curls)',
                '3b' => 'Type 3B (Bouncy Curls)',
                '3c' => 'Type 3C (Tight Curls)',
                '4a' => 'Type 4A (Coily)',
                '4b' => 'Type 4B (Z-Pattern)',
                '4c' => 'Type 4C (Tight Coils)',
                'diverse' => 'Full Range (3A → 4C)',
            ],
            'note' => 'Natural hair texture/curl pattern',
        ],
        
        'face_features' => [
            'label' => 'Face Features',
            'type' => 'select',
            'tags' => 'name="black_rep_face_features"',
            'value' => qa_opt('black_rep_face_features'),
            'options' => [
                '' => 'Not specified',
                'broad_nose' => 'Broad Nose',
                'full_lips' => 'Full Lips',
                'natural' => 'Natural Black Features',
                'diverse' => 'Diverse Black Features',
            ],
            'note' => 'Facial feature preferences',
        ],
        
        'age' => [
            'label' => 'Age Range',
            'type' => 'select',
            'tags' => 'name="black_rep_age"',
            'value' => qa_opt('black_rep_age'),
            'options' => [
                '' => 'Not specified',
                'child' => 'Child',
                'teen' => 'Teen',
                'young_adult' => 'Young Adult',
                'adult' => 'Adult',
                'senior' => 'Senior',
            ],
        ],
        
        'gender' => [
            'label' => 'Gender',
            'type' => 'select',
            'tags' => 'name="black_rep_gender"',
            'value' => qa_opt('black_rep_gender'),
            'options' => [
                '' => 'Not specified',
                'male' => 'Male',
                'female' => 'Female',
                'non_binary' => 'Non-Binary',
            ],
        ],
        
        'header_style' => [
            'type' => 'static',
            'label' => '<h3>Beauty & Style</h3>',
        ],
        
        'makeup' => [
            'label' => 'Makeup Level',
            'type' => 'select',
            'tags' => 'name="black_rep_makeup"',
            'value' => qa_opt('black_rep_makeup'),
            'options' => [
                '' => 'Not specified',
                'none' => 'No Makeup',
                'natural' => 'Natural',
                'glam' => 'Glam',
            ],
        ],
        
        'lighting' => [
            'label' => 'Lighting Style',
            'type' => 'select',
            'tags' => 'name="black_rep_lighting"',
            'value' => qa_opt('black_rep_lighting'),
            'options' => [
                '' => 'Not specified',
                'studio' => 'Studio',
                'natural' => 'Natural',
                'warm' => 'Warm',
                'cool' => 'Cool',
            ],
            'note' => 'Lighting that shows Black skin tones accurately',
        ],
        
        'header_quality' => [
            'type' => 'static',
            'label' => '<h3>Quality Control</h3>',
        ],
        
        'prevent_whitewashing' => [
            'label' => 'Keep Skin Tone Accurate',
            'type' => 'checkbox',
            'value' => qa_opt('black_rep_prevent_whitewashing') == '1',
            'tags' => 'name="black_rep_prevent_whitewashing"',
            'note' => 'Prevent lightening/whitewashing of skin tones',
        ],
        
        'keep_consistent' => [
            'label' => 'Keep Black Features Consistent',
            'type' => 'checkbox',
            'value' => qa_opt('black_rep_keep_consistent') == '1',
            'tags' => 'name="black_rep_keep_consistent"',
            'note' => 'Ensure features stay true to Black phenotypes',
        ],
    ],
    
    'buttons' => [
        'save' => [
            'tags' => 'name="save_black_rep_settings"',
            'label' => 'Save Settings',
            'value' => '1',
        ],
    ],
];

return $qa_content;