<?php
/*
    Ebonix - Black Representation Default Settings
    Sets safe, inclusive defaults for AI generation
*/

if (!defined('QA_VERSION')) {
    header('Location: ../');
    exit;
}

/**
 * Install default representation settings on first activation
 */
function ebonix_install_representation_defaults() {
    
    // Only set defaults if they don't exist
    if (qa_opt('rep_enabled') === null) {
        
        // Enable by default
        qa_opt_set('rep_enabled', true);
        
        // Skin Tone
        qa_opt_set('rep_skin_tone', 'medium_brown');
        qa_opt_set('rep_skin_accuracy', true);
        
        // Hair
        qa_opt_set('rep_curl_pattern', '4c');
        qa_opt_set('rep_hair_style', ''); // No default style
        qa_opt_set('rep_baby_hair', false);
        
        // Facial Features
        qa_opt_set('rep_nose_shape', 'natural');
        qa_opt_set('rep_lip_fullness', 'natural');
        qa_opt_set('rep_face_shape', 'natural');
        
        // Demographics
        qa_opt_set('rep_age_group', 'adult');
        qa_opt_set('rep_gender', 'neutral');
        
        // Beauty & Style
        qa_opt_set('rep_makeup_level', 'natural');
        qa_opt_set('rep_lighting', 'natural');
        
        // Quality Controls
        qa_opt_set('rep_prevent_drift', true);
        qa_opt_set('rep_avoid_stereotypes', true);
        qa_opt_set('rep_maintain_authenticity', true);
        
        // Gateway defaults (disabled by default)
        qa_opt_set('gateway_enabled', false);
        qa_opt_set('gateway_url', 'http://localhost:8000');
        qa_opt_set('gateway_token', '');
    }
}

// Auto-run on include (safe because it checks if already exists)
ebonix_install_representation_defaults();