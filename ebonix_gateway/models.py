"""
Ebonix Gateway - Model Router
"""
import time
import asyncio
import httpx
from typing import Any, Dict
from config import config


class ProviderError(Exception):
    """Raised when provider fails"""
    pass


class ModelRouter:
    
    # ========== IMAGE GENERATION ==========
    
    @staticmethod
    async def generate_image(provider: str, model: str, prompt: str, size: str, negative_prompt: str = "") -> Dict[str, Any]:
        """Generate image"""
        
        if provider == "google":
            return await ModelRouter._google_imagen(prompt, size)
        else:
            raise ProviderError(f"Unknown image provider: {provider}")
    
    @staticmethod
    async def _google_imagen(prompt: str, size: str) -> Dict[str, Any]:
        """Google Imagen 4"""
        
        if not config.GOOGLE_API_KEY:
            raise ProviderError("Google API key not configured")
        
        aspect_map = {
            "1024x1024": "1:1",
            "1024x1792": "9:16",
            "1792x1024": "16:9"
        }
        aspect = aspect_map.get(size, "1:1")
        
        url = f"https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict?key={config.GOOGLE_API_KEY}"
        
        t0 = time.time()
        
        try:
            async with httpx.AsyncClient(timeout=180.0) as client:
                r = await client.post(url, json={
                    "instances": [{"prompt": prompt}],
                    "parameters": {
                        "sampleCount": 1,
                        "aspectRatio": aspect,
                        "personGeneration": "ALLOW_ALL"
                    }
                })
            
            if r.status_code != 200:
                raise ProviderError(f"Google HTTP {r.status_code}: {r.text[:500]}")
            
            data = r.json()
            b64 = data["predictions"][0]["bytesBase64Encoded"]
            
            return {
                "type": "base64",
                "mime": "image/webp",
                "base64": b64,
                "latency_ms": int((time.time() - t0) * 1000)
            }
            
        except httpx.TimeoutException:
            raise ProviderError("Google API timeout")
        except Exception as e:
            raise ProviderError(f"Google error: {str(e)}")
    
    # ========== VIDEO GENERATION ==========
    
    @staticmethod
    async def generate_video(
        provider: str, 
        model: str, 
        prompt: str, 
        aspect_ratio: str = "16:9",
        resolution: str = "540p",
        image_url: str = None
    ) -> Dict[str, Any]:
        """Generate video"""
        
        if provider == "google":
            return await ModelRouter._google_veo(prompt, model, image_url)
        else:
            raise ProviderError(f"Unknown video provider: {provider}")
    
    @staticmethod
    async def _google_veo(prompt: str, model: str, image_url: str = None) -> Dict[str, Any]:
        """Google Veo 3 / Veo 3 Fast"""
        
        if not config.GOOGLE_API_KEY:
            raise ProviderError("Google API key not configured")
        
        # Choose endpoint
        if model == "veo-3-fast":
            endpoint = "veo-3.1-fast-generate-preview:predictLongRunning"
        else:
            endpoint = "veo-3.1-generate-preview:predictLongRunning"
        
        url = f"https://generativelanguage.googleapis.com/v1beta/models/{endpoint}?key={config.GOOGLE_API_KEY}"
        
        payload = {
            "instances": [{"prompt": prompt}]
        }
        
        if image_url:
            payload["instances"][0]["file"] = {"file_uri": image_url}
        
        try:
            # Submit job
            async with httpx.AsyncClient(timeout=30.0) as client:
                r = await client.post(url, json=payload)
            
            if r.status_code != 200:
                raise ProviderError(f"Veo HTTP {r.status_code}: {r.text[:500]}")
            
            data = r.json()
            operation_name = data.get("name")
            
            if not operation_name:
                raise ProviderError("Veo did not return operation name")
            
            # Poll for completion
            status_url = f"https://generativelanguage.googleapis.com/v1beta/{operation_name}?key={config.GOOGLE_API_KEY}"
            max_attempts = 60  # 10 minutes
            
            for attempt in range(max_attempts):
                await asyncio.sleep(10)
                
                async with httpx.AsyncClient(timeout=30.0) as client:
                    r = await client.get(status_url)
                
                status = r.json()
                
                if status.get("done"):
                    video_uri = status["response"]["generateVideoResponse"]["generatedSamples"][0]["video"]["uri"]
                    video_url = f"{video_uri}{'&' if '?' in video_uri else '?'}key={config.GOOGLE_API_KEY}"
                    
                    return {
                        "video_url": video_url,
                        "status": "completed"
                    }
                
                if status.get("error"):
                    raise ProviderError(f"Veo error: {status['error']}")
            
            raise ProviderError("Veo timeout after 10 minutes")
            
        except httpx.TimeoutException:
            raise ProviderError("Veo API timeout")
        except Exception as e:
            raise ProviderError(f"Veo error: {str(e)}")