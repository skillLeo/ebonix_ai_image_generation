"""
Ebonix Gateway - Representation Rules
ALWAYS enforces Black representation
"""

class RepresentationEngine:
    
    def apply_rules(self, prompt: str, rules: dict) -> str:
        """
        Apply Black representation rules to prompt
        CRITICAL: This must ALWAYS result in Black people, never white
        """
        
        if not rules:
            rules = {"default_representation": "diverse_black"}
        
        # Person-related keywords that trigger Black representation
        person_keywords = [
            'person', 'people', 'human',
            'girl', 'boy', 'child', 'kid', 'baby',
            'woman', 'man', 'lady', 'guy',
            'teen', 'teenager', 'adult',
            'beautiful', 'handsome', 'cute', 'pretty', 'gorgeous', 'attractive',
            'model', 'portrait', 'face', 'individual'
        ]
        
        prompt_lower = prompt.lower()
        has_person = any(kw in prompt_lower for kw in person_keywords)
        
        if not has_person:
            # No person keywords, return as-is
            return prompt
        
        # Build enhancements based on rules
        enhancements = []
        
        skin_tone = rules.get("skin_tone", "diverse")
        if skin_tone == "diverse":
            enhancements.append("diverse Black skin tones ranging from light brown to deep ebony")
        elif skin_tone:
            enhancements.append(f"{skin_tone} Black skin tone")
        
        hair_texture = rules.get("hair_texture", "diverse")
        if hair_texture == "diverse":
            enhancements.append("natural Black hair with authentic curl patterns (3A-4C)")
        elif hair_texture:
            enhancements.append(f"type {hair_texture} natural Black hair")
        
        # Always add these base features
        enhancements.append("authentic Black facial features")
        enhancements.append("Black person")
        
        # Quality controls
        if rules.get("prevent_whitewashing", True):
            enhancements.append("NO lightening or whitewashing of skin")
            enhancements.append("accurate Black skin tone without pale or washed-out appearance")
        
        if rules.get("keep_features_consistent", True):
            enhancements.append("maintaining Black features throughout")
            enhancements.append("NO Eurocentric feature drift")
        
        # CRITICAL: Replace beauty adjectives to force Black representation
        enhanced = prompt
        
        beauty_words = {
            'beautiful': 'beautiful Black',
            'Beautiful': 'Beautiful Black',
            'BEAUTIFUL': 'BEAUTIFUL BLACK',
            'handsome': 'handsome Black',
            'Handsome': 'Handsome Black',
            'cute': 'cute Black',
            'Cute': 'Cute Black',
            'pretty': 'pretty Black',
            'Pretty': 'Pretty Black',
            'gorgeous': 'gorgeous Black',
            'Gorgeous': 'Gorgeous Black',
            'attractive': 'attractive Black',
            'Attractive': 'Attractive Black',
        }
        
        for original, replacement in beauty_words.items():
            if original in enhanced:
                enhanced = enhanced.replace(original, replacement)
        
        # If no beauty words but has person keyword, prepend Black
        if enhanced == prompt:  # nothing was replaced
            for kw in ['girl', 'boy', 'woman', 'man', 'person', 'people']:
                if kw in enhanced.lower():
                    # Replace first occurrence with "Black {keyword}"
                    parts = enhanced.split(kw, 1)
                    if len(parts) == 2:
                        enhanced = f"{parts[0]}Black {kw}{parts[1]}"
                        break
        
        # Add all enhancements
        enhanced += f". {', '.join(enhancements)}"
        
        # Final safety: prepend "Black" if not already there
        if 'black' not in enhanced.lower() and 'african' not in enhanced.lower():
            enhanced = f"Black person: {enhanced}"
        
        return enhanced.strip()

representation_engine = RepresentationEngine()