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

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    stream=sys.stdout,
    force=True
)
logger = logging.getLogger(__name__)


# ✅ FIX DEPRECATION WARNING - Use lifespan instead of on_event
@asynccontextmanager
async def lifespan(app: FastAPI):
    # Startup
    logger.info("=" * 80)
    logger.info("🚀 EBONIX GATEWAY STARTED")
    logger.info(f"Host: {config.HOST}")
    logger.info(f"Port: {config.PORT}")
    logger.info(f"Token: {config.GATEWAY_AUTH_TOKEN}")
    logger.info(f"Google API: {'✅ Configured' if config.GOOGLE_API_KEY else '❌ NOT CONFIGURED'}")
    logger.info("=" * 80)
    
    yield  # Server runs here
    
    # Shutdown
    logger.info("🛑 Gateway shutting down")


# ✅ CREATE APP WITH LIFESPAN
app = FastAPI(
    title="Ebonix Gateway",
    version="1.0.0",
    lifespan=lifespan
)


@app.middleware("http")
async def log_requests(request: Request, call_next):
    logger.info(f"📥 {request.method} {request.url.path}")
    response = await call_next(request)
    logger.info(f"📤 {response.status_code}")
    return response


async def verify_token(authorization: str = Header(None)):
    """Verify API token"""
    if not authorization:
        logger.error("❌ No authorization header")
        raise HTTPException(status_code=401, detail="Missing authorization")
    
    token = authorization.replace("Bearer ", "").strip()
    
    if token != config.GATEWAY_AUTH_TOKEN:
        logger.error(f"❌ Invalid token")
        raise HTTPException(status_code=403, detail="Invalid token")
    
    return token


@app.get("/")
async def root():
    """Root endpoint"""
    return {
        "gateway": "Ebonix AI Gateway",
        "version": "1.0.0",
        "status": "running",
        "endpoints": {
            "health": "/health",
            "image": "/generate",
            "video": "/generate_video"
        }
    }


@app.get("/health")
async def health():
    """Health check"""
    return {
        "status": "ok",
        "gateway": "Ebonix",
        "version": "1.0.0",
        "google_api_configured": bool(config.GOOGLE_API_KEY),
        "api_key_length": len(config.GOOGLE_API_KEY) if config.GOOGLE_API_KEY else 0
    }


@app.post("/generate")
async def generate_image(request: Dict[str, Any], token: str = Depends(verify_token)):
    """IMAGE GENERATION"""
    
    logger.info("=" * 80)
    logger.info("🖼️  IMAGE GENERATION REQUEST")
    logger.info("=" * 80)
    
    try:
        prompt = request.get("prompt", "").strip()
        if not prompt:
            raise HTTPException(status_code=400, detail="Prompt required")
        
        logger.info(f"📝 Original: {prompt}")
        
        # Apply Black representation rules
        rules = request.get("representation_rules", {"default_representation": "diverse_black"})
        enhanced_prompt = representation_engine.apply_rules(prompt, rules)
        logger.info(f"✨ Enhanced: {enhanced_prompt}")
        
        # Check API key
        if not config.GOOGLE_API_KEY:
            logger.error("❌ Google API key not configured!")
            return {"success": False, "error": "Gateway API key not configured"}
        
        # Generate
        result = await ModelRouter.generate_image(
            provider="google",
            model="imagen-4",
            prompt=enhanced_prompt,
            size=request.get("size", "1024x1024"),
            negative_prompt=""
        )
        
        logger.info(f"✅ SUCCESS! {result.get('latency_ms', 0)}ms")
        
        image_url = result.get("url") or f"data:image/webp;base64,{result.get('base64', '')}"
        
        return {
            "success": True,
            "image_url": image_url,
            "enhanced_prompt": enhanced_prompt,
            "latency_ms": result.get("latency_ms", 0)
        }
        
    except HTTPException:
        raise
    except ProviderError as e:
        logger.error(f"❌ Provider error: {e}")
        return {"success": False, "error": str(e)}
    except Exception as e:
        logger.error(f"❌ Error: {e}", exc_info=True)
        return {"success": False, "error": str(e)}


@app.post("/generate_video")
async def generate_video(request: Dict[str, Any], token: str = Depends(verify_token)):
    """VIDEO GENERATION"""
    
    logger.info("=" * 80)
    logger.info("🎬 VIDEO GENERATION REQUEST")
    logger.info("=" * 80)
    
    try:
        prompt = request.get("prompt", "").strip()
        if not prompt:
            raise HTTPException(status_code=400, detail="Prompt required")
        
        logger.info(f"📝 Original: {prompt}")
        
        # Apply Black representation rules
        rules = request.get("representation_rules", {"default_representation": "diverse_black"})
        enhanced_prompt = representation_engine.apply_rules(prompt, rules)
        logger.info(f"✨ Enhanced: {enhanced_prompt}")
        
        # Check API key
        if not config.GOOGLE_API_KEY:
            logger.error("❌ Google API key not configured!")
            return {"success": False, "error": "Gateway API key not configured"}
        
        model = request.get("model", "veo3f")
        logger.info(f"🎯 Model: {model}")
        
        # Map model to provider
        provider_map = {
            'veo3': ('google', 'veo-3'),
            'veo3f': ('google', 'veo-3-fast'),
        }
        
        if model not in provider_map:
            logger.warning(f"⚠️  Unknown model '{model}', using veo3f")
            model = 'veo3f'
        
        provider, model_name = provider_map[model]
        logger.info(f"☁️  Provider: {provider}, Model: {model_name}")
        logger.info("⏳ This may take 5-10 minutes...")
        
        # Generate video
        result = await ModelRouter.generate_video(
            provider=provider,
            model=model_name,
            prompt=enhanced_prompt,
            aspect_ratio=request.get("aspect_ratio", "16:9"),
            resolution=request.get("resolution", "540p"),
            image_url=request.get("image_url")
        )
        
        logger.info("✅ VIDEO SUCCESS!")
        
        response = {
            "success": True,
            "enhanced_prompt": enhanced_prompt,
        }
        
        if 'video_url' in result:
            response['video_url'] = result['video_url']
            response['status'] = 'completed'
        elif 'job_id' in result:
            response['job_id'] = result['job_id']
            response['status'] = 'processing'
        
        logger.info(f"📦 Response: {response}")
        return response
        
    except HTTPException:
        raise
    except ProviderError as e:
        logger.error(f"❌ Provider error: {e}")
        return {"success": False, "error": str(e)}
    except Exception as e:
        logger.error(f"❌ Error: {e}", exc_info=True)
        return {"success": False, "error": str(e)}


# ✅ CRITICAL: ADD UVICORN LAUNCHER
if __name__ == "__main__":
    import uvicorn
    
    print("=" * 80)
    print("🚀 STARTING EBONIX GATEWAY")
    print(f"📍 Port: {config.PORT}")
    print(f"🔑 Token: {config.GATEWAY_AUTH_TOKEN}")
    print("=" * 80)
    
    uvicorn.run(
        app,
        host=config.HOST,
        port=config.PORT,
        log_level="info"
    )