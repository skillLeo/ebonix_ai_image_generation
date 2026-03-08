"""
Ebonix Gateway - Entry Point
"""
import sys
import logging
import uvicorn
from config import config

# Force logging to stdout
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    stream=sys.stdout,
    force=True
)

logger = logging.getLogger(__name__)

if __name__ == "__main__":
    print("=" * 80, flush=True)
    print("🚀 EBONIX GATEWAY STARTING", flush=True)
    print("=" * 80, flush=True)
    print(f"Host: {config.HOST}:{config.PORT}", flush=True)
    print(f"Token: {config.GATEWAY_AUTH_TOKEN}", flush=True)
    print(f"Google API: {'✅ Configured' if config.GOOGLE_API_KEY else '❌ NOT SET'}", flush=True)
    print("=" * 80, flush=True)
    print("", flush=True)
    
    if not config.GOOGLE_API_KEY:
        print("⚠️  WARNING: GOOGLE_API_KEY not configured!", flush=True)
        print("", flush=True)
    
    try:
        # Run with access log enabled
        uvicorn.run(
            "main:app",
            host=config.HOST,
            port=config.PORT,
            reload=False,
            log_level="info",
            access_log=True
        )
    except Exception as e:
        print(f"❌ ERROR: {e}", flush=True)
        sys.exit(1)