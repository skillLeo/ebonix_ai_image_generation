"""
Ebonix Gateway - Main FastAPI Application
"""
from fastapi import FastAPI, HTTPException, Depends, Header, Request
from contextlib import asynccontextmanager
from typing import Dict, Any
import logging
import sys

from config import config
from models import ModelRouter, ProviderError
from representation import representation_engine

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    stream=sys.stdout,
    force=True
)
logger = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(app: FastAPI):
    logger.info("=" * 80)
    logger.info("🚀 EBONIX GATEWAY STARTED")
    logger.info(f"Host:        {config.HOST}")
    logger.info(f"Port:        {config.PORT}")
    logger.info(f"Google API:  {'✅ configured' if config.GOOGLE_API_KEY else '❌ NOT SET'}")
    logger.info(f"Fal API:     {'✅ configured' if config.FAL_API_KEY    else '❌ NOT SET'}")
    logger.info("=" * 80)
    yield
    logger.info("🛑 Gateway shutting down")


app = FastAPI(title="Ebonix Gateway", version="1.0.0", lifespan=lifespan)


@app.middleware("http")
async def log_requests(request: Request, call_next):
    logger.info(f"📥 {request.method} {request.url.path}")
    response = await call_next(request)
    logger.info(f"📤 {response.status_code}")
    return response


async def verify_token(authorization: str = Header(None)):
    if not authorization:
        raise HTTPException(status_code=401, detail="Missing authorization header")
    token = authorization.replace("Bearer ", "").strip()
    if token != config.GATEWAY_AUTH_TOKEN:
        raise HTTPException(status_code=403, detail="Invalid token")
    return token


# ─────────────────────────────────────────────────────────────────────────────
# HEALTH / ROOT
# ─────────────────────────────────────────────────────────────────────────────

@app.get("/")
async def root():
    return {
        "gateway": "Ebonix AI Gateway",
        "version": "1.0.0",
        "status":  "running",
    }


@app.get("/health")
async def health():
    return {
        "status":               "ok",
        "google_api_configured": bool(config.GOOGLE_API_KEY),
        "fal_api_configured":    bool(config.FAL_API_KEY),
    }


# ─────────────────────────────────────────────────────────────────────────────
# TEXT-TO-IMAGE (existing)
# ─────────────────────────────────────────────────────────────────────────────

@app.post("/generate")
async def generate_image(request: Dict[str, Any], token: str = Depends(verify_token)):
    logger.info("🖼️  IMAGE GENERATION REQUEST")
    try:
        prompt = request.get("prompt", "").strip()
        if not prompt:
            raise HTTPException(status_code=400, detail="prompt required")

        rules            = request.get("representation_rules", {"default_representation": "diverse_black"})
        enhanced_prompt  = representation_engine.apply_rules(prompt, rules)
        logger.info(f"Enhanced: {enhanced_prompt[:80]}")

        if not config.GOOGLE_API_KEY:
            return {"success": False, "error": "Google API key not configured"}

        result    = await ModelRouter.generate_image("google", "imagen-4", enhanced_prompt, request.get("size", "1024x1024"))
        image_url = result.get("url") or f"data:image/webp;base64,{result.get('base64', '')}"

        return {
            "success":         True,
            "image_url":       image_url,
            "enhanced_prompt": enhanced_prompt,
            "latency_ms":      result.get("latency_ms", 0),
        }

    except HTTPException:
        raise
    except ProviderError as exc:
        logger.error(f"❌ Provider: {exc}")
        return {"success": False, "error": str(exc)}
    except Exception as exc:
        logger.error(f"❌ Error: {exc}", exc_info=True)
        return {"success": False, "error": str(exc)}


# ─────────────────────────────────────────────────────────────────────────────
# VIDEO GENERATION (existing)
# ─────────────────────────────────────────────────────────────────────────────

@app.post("/generate_video")
async def generate_video(request: Dict[str, Any], token: str = Depends(verify_token)):
    logger.info("🎬 VIDEO GENERATION REQUEST")
    try:
        prompt = request.get("prompt", "").strip()
        if not prompt:
            raise HTTPException(status_code=400, detail="prompt required")

        rules           = request.get("representation_rules", {"default_representation": "diverse_black"})
        enhanced_prompt = representation_engine.apply_rules(prompt, rules)

        if not config.GOOGLE_API_KEY:
            return {"success": False, "error": "Google API key not configured"}

        model = request.get("model", "veo3f")
        provider_map = {"veo3": ("google", "veo-3"), "veo3f": ("google", "veo-3-fast")}
        if model not in provider_map:
            model = "veo3f"
        provider, model_name = provider_map[model]

        result   = await ModelRouter.generate_video(provider, model_name, enhanced_prompt,
                       request.get("aspect_ratio", "16:9"), request.get("resolution", "540p"),
                       request.get("image_url"))
        response = {"success": True, "enhanced_prompt": enhanced_prompt}
        if "video_url" in result:
            response.update({"video_url": result["video_url"], "status": "completed"})
        elif "job_id" in result:
            response.update({"job_id": result["job_id"], "status": "processing"})
        return response

    except HTTPException:
        raise
    except ProviderError as exc:
        return {"success": False, "error": str(exc)}
    except Exception as exc:
        logger.error(f"❌ Error: {exc}", exc_info=True)
        return {"success": False, "error": str(exc)}


# ─────────────────────────────────────────────────────────────────────────────
# NEW: SELFIE TRANSFORMATION
# ─────────────────────────────────────────────────────────────────────────────

@app.post("/transform_selfie")
async def transform_selfie(request: Dict[str, Any], token: str = Depends(verify_token)):
    """
    Selfie transformation pipeline:
      1. Gemini Vision detects if person is Black / African-descent
      2. Black  → protection prompt (preserve melanin, hair, features)
         Other  → style-only prompt (NEVER touch skin tone or race)
      3. Fal AI FLUX.1 Kontext performs the transformation
      4. Returns image_urls array
    """
    logger.info("=" * 80)
    logger.info("📸 SELFIE TRANSFORMATION REQUEST")
    logger.info("=" * 80)

    try:
        image_b64         = request.get("image_b64", "").strip()
        mime_type         = request.get("mime_type", "image/jpeg")
        style_preset      = request.get("style_preset", "selfie_soft_glam")
        additional_prompt = request.get("additional_prompt", "").strip()

        if not image_b64:
            raise HTTPException(status_code=400, detail="image_b64 is required")
        if not config.FAL_API_KEY:
            return {"success": False, "error": "Fal API key not configured on gateway"}

        logger.info(f"Style: {style_preset} | Mime: {mime_type} | Extra: {additional_prompt[:50]}")

        # ── Step 1: Detect person ────────────────────────────────────────────
        logger.info("🔍 Running person detection via Gemini Vision...")
        detection  = await representation_engine.detect_person(image_b64, mime_type)
        is_black   = detection.get("is_black", False)
        confidence = detection.get("confidence", "unknown")
        logger.info(f"Detection result: is_black={is_black}  confidence={confidence}")

        # ── Step 2: Build appropriate prompt ────────────────────────────────
        if is_black:
            logger.info("✊ Black person detected → applying PROTECTION rules")
            prompt = representation_engine.build_black_protection_prompt(
                style_preset, additional_prompt
            )
        else:
            logger.info("🎨 Non-Black person → applying STYLE-ONLY rules")
            prompt = representation_engine.build_style_only_prompt(
                style_preset, additional_prompt
            )

        logger.info(f"📝 Prompt (first 120 chars): {prompt[:120]}")

        # ── Step 3: Transform via Fal FLUX.1 Kontext ────────────────────────
        logger.info("🚀 Sending to Fal AI FLUX.1 Kontext...")
        result = await ModelRouter.fal_flux_kontext(image_b64, mime_type, prompt, num_images=2)

        urls = result.get("urls", [])
        logger.info(f"✅ SELFIE DONE — {len(urls)} image(s) returned")

        return {
            "success":        True,
            "image_urls":     urls,
            "detected_black": is_black,
            "prompt_used":    prompt,
        }

    except HTTPException:
        raise
    except ProviderError as exc:
        logger.error(f"❌ Fal error: {exc}")
        return {"success": False, "error": str(exc)}
    except Exception as exc:
        logger.error(f"❌ Selfie error: {exc}", exc_info=True)
        return {"success": False, "error": str(exc)}


# ─────────────────────────────────────────────────────────────────────────────
# LAUNCH
# ─────────────────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host=config.HOST, port=config.PORT, log_level="info")