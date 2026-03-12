"""
Ebonix Gateway - Representation Engine
Handles:
  1. Text-prompt Black representation rules (existing behaviour)
  2. Photo person detection via Gemini Vision (new)
  3. Prompt building for selfie transformations (new)
"""
import logging
import httpx
from typing import Dict, Any

from config import config

logger = logging.getLogger(__name__)


# ─────────────────────────────────────────────────────────────────────────────
# Selfie style prompts — BLACK person (protect + celebrate identity)
# ─────────────────────────────────────────────────────────────────────────────
_PROMPTS_BLACK: Dict[str, str] = {
    'selfie_luxury_editorial': (
        "Transform this person into a luxury fashion editorial portrait. "
        "High-end designer fashion, dramatic studio lighting with deep shadows, "
        "magazine cover composition. "
        "MANDATORY: Keep and ENHANCE the person's exact melanin-rich skin tone — "
        "preserve all depth and richness, never lighten. "
        "Keep exact natural Black hair texture and curl pattern. "
        "Keep all authentic Black facial features completely unchanged. "
        "Absolutely NO skin tone lightening, NO Eurocentric feature drift."
    ),
    'selfie_soft_glam': (
        "Transform this person into a soft glam beauty portrait. "
        "Luminous glowing skin that celebrates deep melanin beautifully, "
        "warm golden-hour lighting that flatters dark skin tones, soft bokeh background. "
        "MANDATORY: Keep exact skin tone — warm and richly melanated. "
        "Keep natural Black hair texture and curl pattern. "
        "Keep all facial features unchanged. NO skin lightening."
    ),
    'selfie_professional': (
        "Transform this person into a professional corporate headshot. "
        "Clean neutral background, business-professional attire, "
        "balanced studio lighting that accurately represents dark skin tones. "
        "MANDATORY: Keep exact skin tone, natural Black hair, "
        "all facial features unchanged. NO skin lightening or bleaching."
    ),
    'selfie_vacation': (
        "Transform this person into a vibrant vacation lifestyle photo. "
        "Warm golden-hour setting, travel backdrop, casual chic style, "
        "sun-kissed glow that honours deep melanin beautifully. "
        "MANDATORY: Keep exact melanin-rich skin tone, natural Black hair texture, "
        "all facial features unchanged."
    ),
    'selfie_afro_futurist': (
        "Transform this person into a bold Afro-futurist aesthetic portrait. "
        "Vibrant colours inspired by African futurism, regal cultural styling, "
        "powerful presence, celebration of Black heritage and identity. "
        "MANDATORY: Keep and CELEBRATE exact skin tone, "
        "natural Black hair (afro, locs, twists — exactly as shown), "
        "all authentic Black facial features. "
        "This is a tribute to Black excellence."
    ),
}

# ─────────────────────────────────────────────────────────────────────────────
# Selfie style prompts — NON-BLACK person (style only, identity 100% protected)
# ─────────────────────────────────────────────────────────────────────────────
_PROMPTS_STYLE_ONLY: Dict[str, str] = {
    'selfie_luxury_editorial': (
        "Transform this photo into a luxury fashion editorial portrait. "
        "Apply: high-end designer fashion, dramatic studio lighting, magazine composition. "
        "ABSOLUTE RULE: DO NOT change skin tone, race, ethnicity, facial structure, "
        "eye shape, hair color, or any physical characteristic of this person. "
        "Change ONLY clothing, lighting, and background."
    ),
    'selfie_soft_glam': (
        "Transform this photo into a soft glam beauty portrait. "
        "Apply: natural glowing skin finish, warm golden-hour lighting, "
        "soft bokeh background, elegant styling. "
        "ABSOLUTE RULE: DO NOT change skin tone, race, ethnicity, facial structure, "
        "or any physical characteristic of this person."
    ),
    'selfie_professional': (
        "Transform this photo into a professional corporate headshot. "
        "Apply: clean neutral background, business-professional attire, "
        "balanced soft studio lighting. "
        "ABSOLUTE RULE: DO NOT change skin tone, race, ethnicity, facial structure, "
        "or any physical characteristic of this person."
    ),
    'selfie_vacation': (
        "Transform this photo into a vibrant vacation lifestyle photo. "
        "Apply: bright warm golden-hour setting, travel backdrop, casual chic style. "
        "ABSOLUTE RULE: DO NOT change skin tone, race, ethnicity, facial structure, "
        "or any physical characteristic of this person."
    ),
    'selfie_afro_futurist': (
        "Transform this photo into a stylized Afro-futurist inspired portrait. "
        "Apply: bold vibrant colours, futurist styling elements, cultural visual motifs, "
        "powerful dramatic composition. "
        "ABSOLUTE RULE: DO NOT change skin tone, race, ethnicity, facial structure, "
        "or any physical characteristic of this person."
    ),
}

_DEFAULT_PRESET = 'selfie_soft_glam'


class RepresentationEngine:

    # ─────────────────────────────────────────────────────────────────────────
    # Existing: text-prompt Black representation rules (unchanged)
    # ─────────────────────────────────────────────────────────────────────────

    def apply_rules(self, prompt: str, rules: dict) -> str:
        """Apply Black representation rules to text-only prompts."""
        if not rules:
            rules = {"default_representation": "diverse_black"}

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
            return prompt

        enhancements = []
        skin_tone    = rules.get("skin_tone", "diverse")
        hair_texture = rules.get("hair_texture", "diverse")

        if skin_tone == "diverse":
            enhancements.append("diverse Black skin tones ranging from light brown to deep ebony")
        elif skin_tone:
            enhancements.append(f"{skin_tone} Black skin tone")

        if hair_texture == "diverse":
            enhancements.append("natural Black hair with authentic curl patterns (3A-4C)")
        elif hair_texture:
            enhancements.append(f"type {hair_texture} natural Black hair")

        enhancements.extend(["authentic Black facial features", "Black person"])

        if rules.get("prevent_whitewashing", True):
            enhancements.extend([
                "NO lightening or whitewashing of skin",
                "accurate Black skin tone without pale or washed-out appearance",
            ])
        if rules.get("keep_features_consistent", True):
            enhancements.extend([
                "maintaining Black features throughout",
                "NO Eurocentric feature drift",
            ])

        enhanced = prompt
        beauty_words = {
            'beautiful': 'beautiful Black', 'Beautiful': 'Beautiful Black',
            'handsome':  'handsome Black',  'Handsome':  'Handsome Black',
            'cute':      'cute Black',      'Cute':      'Cute Black',
            'pretty':    'pretty Black',    'Pretty':    'Pretty Black',
            'gorgeous':  'gorgeous Black',  'Gorgeous':  'Gorgeous Black',
            'attractive':'attractive Black','Attractive': 'Attractive Black',
        }
        for original, replacement in beauty_words.items():
            if original in enhanced:
                enhanced = enhanced.replace(original, replacement)

        if enhanced == prompt:
            for kw in ['girl', 'boy', 'woman', 'man', 'person', 'people']:
                if kw in enhanced.lower():
                    parts = enhanced.split(kw, 1)
                    if len(parts) == 2:
                        enhanced = f"{parts[0]}Black {kw}{parts[1]}"
                        break

        enhanced += f". {', '.join(enhancements)}"

        if 'black' not in enhanced.lower() and 'african' not in enhanced.lower():
            enhanced = f"Black person: {enhanced}"

        return enhanced.strip()

    # ─────────────────────────────────────────────────────────────────────────
    # NEW: Detect person in selfie via Gemini Vision
    # ─────────────────────────────────────────────────────────────────────────

    async def detect_person(self, image_b64: str, mime_type: str) -> Dict[str, Any]:
        """
        Use Gemini 1.5 Flash to detect if the person in the photo is
        Black / African-descent.

        Returns:
            {"is_black": bool, "confidence": str}

        Defaults to is_black=False on any failure so style-only rules apply
        (safest fallback — never incorrectly forces representation changes).
        """
        if not config.GOOGLE_API_KEY:
            logger.warning("detect_person: no GOOGLE_API_KEY — defaulting style-only")
            return {"is_black": False, "confidence": "no_api_key"}

        url = (
            "https://generativelanguage.googleapis.com/v1beta/models/"
            f"gemini-1.5-flash:generateContent?key={config.GOOGLE_API_KEY}"
        )

        payload = {
            "contents": [{
                "parts": [
                    {
                        "inline_data": {
                            "mime_type": mime_type,
                            "data": image_b64,
                        }
                    },
                    {
                        "text": (
                            "Look at the person in this photo. "
                            "Does this person appear to be of Black or African descent? "
                            "Consider skin tone, facial features, and phenotype. "
                            "Answer with ONLY the single word 'yes' or 'no'. "
                            "If you cannot determine with confidence, answer 'no'."
                        )
                    }
                ]
            }],
            "generationConfig": {
                "maxOutputTokens": 5,
                "temperature": 0.0,
            },
            "safetySettings": [
                {"category": "HARM_CATEGORY_HARASSMENT",        "threshold": "BLOCK_NONE"},
                {"category": "HARM_CATEGORY_HATE_SPEECH",       "threshold": "BLOCK_NONE"},
                {"category": "HARM_CATEGORY_SEXUALLY_EXPLICIT", "threshold": "BLOCK_NONE"},
                {"category": "HARM_CATEGORY_DANGEROUS_CONTENT", "threshold": "BLOCK_NONE"},
            ],
        }

        try:
            async with httpx.AsyncClient(timeout=30.0) as client:
                r = await client.post(url, json=payload)

            if r.status_code != 200:
                logger.error(f"detect_person: Gemini HTTP {r.status_code}: {r.text[:200]}")
                return {"is_black": False, "confidence": "api_error"}

            text = (
                r.json()
                 .get("candidates", [{}])[0]
                 .get("content", {})
                 .get("parts", [{}])[0]
                 .get("text", "no")
                 .strip()
                 .lower()
            )
            is_black = text.startswith("yes")
            logger.info(f"detect_person: raw='{text}' → is_black={is_black}")
            return {"is_black": is_black, "confidence": "gemini_vision"}

        except Exception as exc:
            logger.error(f"detect_person: exception: {exc}")
            return {"is_black": False, "confidence": "exception"}

    # ─────────────────────────────────────────────────────────────────────────
    # NEW: Prompt builders
    # ─────────────────────────────────────────────────────────────────────────

    def build_black_protection_prompt(self, style_preset: str, additional: str = "") -> str:
        """Build prompt for Black person: apply style + protect identity."""
        base = _PROMPTS_BLACK.get(style_preset, _PROMPTS_BLACK[_DEFAULT_PRESET])
        if additional:
            base += f" Additional styling requested: {additional}"
        return base

    def build_style_only_prompt(self, style_preset: str, additional: str = "") -> str:
        """Build prompt for non-Black person: apply style only, never change race."""
        base = _PROMPTS_STYLE_ONLY.get(style_preset, _PROMPTS_STYLE_ONLY[_DEFAULT_PRESET])
        if additional:
            base += f" Additional styling requested: {additional}"
        return base


representation_engine = RepresentationEngine()