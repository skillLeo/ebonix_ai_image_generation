"""
Ebonix Gateway - Model Router
"""
import base64
import time
import asyncio
import httpx
from typing import Any, Dict

from config import config


class ProviderError(Exception):
    """Raised when a provider fails."""
    pass


class ModelRouter:

    # =========================================================================
    # IMAGE GENERATION (existing)
    # =========================================================================

    @staticmethod
    async def generate_image(
        provider: str, model: str, prompt: str, size: str, negative_prompt: str = ""
    ) -> Dict[str, Any]:
        if provider == "google":
            return await ModelRouter._google_imagen(prompt, size)
        raise ProviderError(f"Unknown image provider: {provider}")

    @staticmethod
    async def _google_imagen(prompt: str, size: str) -> Dict[str, Any]:
        """Google Imagen 4 — text-to-image."""
        if not config.GOOGLE_API_KEY:
            raise ProviderError("Google API key not configured")

        aspect_map = {
            "1024x1024": "1:1",
            "1024x1792": "9:16",
            "1792x1024": "16:9",
        }
        aspect = aspect_map.get(size, "1:1")
        url    = (
            "https://generativelanguage.googleapis.com/v1beta/models/"
            f"imagen-4.0-generate-001:predict?key={config.GOOGLE_API_KEY}"
        )
        t0 = time.time()

        try:
            async with httpx.AsyncClient(timeout=180.0) as client:
                r = await client.post(url, json={
                    "instances":  [{"prompt": prompt}],
                    "parameters": {
                        "sampleCount":      1,
                        "aspectRatio":      aspect,
                        "personGeneration": "ALLOW_ALL",
                    },
                })

            if r.status_code != 200:
                raise ProviderError(f"Google HTTP {r.status_code}: {r.text[:500]}")

            b64 = r.json()["predictions"][0]["bytesBase64Encoded"]
            return {
                "type":       "base64",
                "mime":       "image/webp",
                "base64":     b64,
                "latency_ms": int((time.time() - t0) * 1000),
            }

        except httpx.TimeoutException:
            raise ProviderError("Google Imagen API timeout")
        except ProviderError:
            raise
        except Exception as exc:
            raise ProviderError(f"Google Imagen error: {exc}")

    # =========================================================================
    # VIDEO GENERATION (existing)
    # =========================================================================

    @staticmethod
    async def generate_video(
        provider: str, model: str, prompt: str,
        aspect_ratio: str = "16:9", resolution: str = "540p",
        image_url: str = None
    ) -> Dict[str, Any]:
        if provider == "google":
            return await ModelRouter._google_veo(prompt, model, image_url)
        raise ProviderError(f"Unknown video provider: {provider}")

    @staticmethod
    async def _google_veo(prompt: str, model: str, image_url: str = None) -> Dict[str, Any]:
        """Google Veo 3 / Veo 3 Fast."""
        if not config.GOOGLE_API_KEY:
            raise ProviderError("Google API key not configured")

        endpoint = (
            "veo-3.1-fast-generate-preview:predictLongRunning"
            if model == "veo-3-fast"
            else "veo-3.1-generate-preview:predictLongRunning"
        )
        url = (
            "https://generativelanguage.googleapis.com/v1beta/models/"
            f"{endpoint}?key={config.GOOGLE_API_KEY}"
        )
        payload = {"instances": [{"prompt": prompt}]}
        if image_url:
            payload["instances"][0]["file"] = {"file_uri": image_url}

        try:
            async with httpx.AsyncClient(timeout=30.0) as client:
                r = await client.post(url, json=payload)

            if r.status_code != 200:
                raise ProviderError(f"Veo HTTP {r.status_code}: {r.text[:500]}")

            operation_name = r.json().get("name")
            if not operation_name:
                raise ProviderError("Veo returned no operation name")

            status_url = (
                f"https://generativelanguage.googleapis.com/v1beta/"
                f"{operation_name}?key={config.GOOGLE_API_KEY}"
            )

            for _ in range(60):
                await asyncio.sleep(10)
                async with httpx.AsyncClient(timeout=30.0) as client:
                    r = await client.get(status_url)
                status = r.json()
                if status.get("done"):
                    uri = (
                        status["response"]["generateVideoResponse"]
                               ["generatedSamples"][0]["video"]["uri"]
                    )
                    video_url = f"{uri}{'&' if '?' in uri else '?'}key={config.GOOGLE_API_KEY}"
                    return {"video_url": video_url, "status": "completed"}
                if status.get("error"):
                    raise ProviderError(f"Veo error: {status['error']}")

            raise ProviderError("Veo timeout after 10 minutes")

        except httpx.TimeoutException:
            raise ProviderError("Veo API timeout")
        except ProviderError:
            raise
        except Exception as exc:
            raise ProviderError(f"Veo error: {exc}")

    # =========================================================================
    # NEW: FAL AI FLUX.1 KONTEXT (selfie image-to-image transformation)
    # =========================================================================

    @staticmethod
    async def fal_flux_kontext(
        image_b64: str,
        mime_type: str,
        prompt: str,
        num_images: int = 2,
    ) -> Dict[str, Any]:
        """
        Fal AI FLUX.1 Kontext — image-to-image transformation.

        Flow:
          1. Upload image binary to Fal storage → fal_image_url
          2. Submit job to Fal queue → request_id
          3. Poll /status until COMPLETED | FAILED (max 7.5 min)
          4. Fetch result → return image URLs
        """
        if not config.FAL_API_KEY:
            raise ProviderError("Fal API key not configured — set FAL_API_KEY in .env")

        auth   = {"Authorization": f"Key {config.FAL_API_KEY}"}
        t0     = time.time()
        binary = base64.b64decode(image_b64)

        # ── Step 1: Upload to Fal storage ────────────────────────────────────
        try:
            async with httpx.AsyncClient(timeout=90.0) as client:
                r = await client.post(
                    "https://rest.alpha.fal.ai/storage/upload/data",
                    headers={**auth, "Content-Type": mime_type},
                    content=binary,
                )
        except httpx.TimeoutException:
            raise ProviderError("Fal storage upload timed out (90s)")

        if r.status_code != 200:
            raise ProviderError(
                f"Fal storage upload HTTP {r.status_code}: {r.text[:300]}"
            )

        fal_image_url = r.json().get("access_url") or r.json().get("url", "")
        if not fal_image_url:
            raise ProviderError("Fal storage returned no access_url")

        import logging
        logger = logging.getLogger(__name__)
        logger.info(f"Fal storage OK: {fal_image_url}")

        # ── Step 2: Submit job to queue ──────────────────────────────────────
        submit_payload = {
            "prompt":                prompt,
            "image_url":             fal_image_url,
            "num_images":            max(1, min(num_images, 4)),
            "guidance_scale":        2.5,
            "num_inference_steps":   28,
            "output_format":         "jpeg",
            "safety_tolerance":      "2",
            "enable_safety_checker": False,
        }

        try:
            async with httpx.AsyncClient(timeout=60.0) as client:
                r = await client.post(
                    "https://queue.fal.run/fal-ai/flux-pro/kontext",
                    headers={**auth, "Content-Type": "application/json"},
                    json=submit_payload,
                )
        except httpx.TimeoutException:
            raise ProviderError("Fal queue submit timed out")

        if r.status_code not in (200, 201):
            raise ProviderError(
                f"Fal queue submit HTTP {r.status_code}: {r.text[:300]}"
            )

        request_id = r.json().get("request_id", "")
        if not request_id:
            raise ProviderError(f"Fal returned no request_id. Body: {r.text[:200]}")

        logger.info(f"Fal job submitted: request_id={request_id}")

        # ── Step 3: Poll until COMPLETED or FAILED ───────────────────────────
        base_url   = f"https://queue.fal.run/fal-ai/flux-pro/kontext/requests/{request_id}"
        status_url = f"{base_url}/status"

        for attempt in range(90):          # 90 × 5s = 7.5 minutes max
            await asyncio.sleep(5)

            try:
                async with httpx.AsyncClient(timeout=30.0) as client:
                    r = await client.get(status_url, headers=auth)
            except Exception as poll_exc:
                logger.warning(f"Fal poll attempt {attempt} exception: {poll_exc}")
                continue

            if r.status_code != 200:
                logger.warning(f"Fal status HTTP {r.status_code} at attempt {attempt}")
                continue

            body  = r.json()
            state = body.get("status", "").upper()
            logger.info(
                f"Fal poll attempt={attempt} state={state} "
                f"elapsed={int(time.time()-t0)}s"
            )

            if state == "COMPLETED":
                try:
                    async with httpx.AsyncClient(timeout=30.0) as client:
                        r = await client.get(base_url, headers=auth)
                except Exception as fetch_exc:
                    raise ProviderError(f"Fal result fetch failed: {fetch_exc}")

                if r.status_code != 200:
                    raise ProviderError(f"Fal result fetch HTTP {r.status_code}")

                images = r.json().get("images", [])
                if not images:
                    raise ProviderError("Fal COMPLETED but returned no images")

                urls = [img["url"] for img in images if img.get("url")]
                logger.info(
                    f"Fal KONTEXT DONE: {len(urls)} image(s) "
                    f"in {int(time.time()-t0)}s"
                )
                return {"urls": urls, "latency_ms": int((time.time() - t0) * 1000)}

            if state == "FAILED":
                err = body.get("error") or body.get("detail") or "Unknown Fal error"
                raise ProviderError(f"Fal generation FAILED: {err}")

            # IN_QUEUE / IN_PROGRESS → keep polling

        elapsed = int(time.time() - t0)
        raise ProviderError(f"Fal KONTEXT timed out after {elapsed}s")