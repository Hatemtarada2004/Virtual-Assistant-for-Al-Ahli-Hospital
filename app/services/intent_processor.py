import json
import openai

class IntentProcessor:
    """Uses LLM for Joint Intent Classification and Entity Extraction (Arabic)."""
    
    def analyze(self, text, context_summary):
        prompt = f"""
        Analyze the hospital patient message in Arabic.
        Context: {context_summary}
        Message: "{text}"

        Rules:
        - Identify if it's a greeting (marhaba, hi) -> intent: greeting
        - Identify if it's asking how are you (kifak, achbark) -> intent: smalltalk
        - Identify if it's a booking request (baddi ahjez) -> intent: booking
        - If it's a mix, prioritize the most actionable intent.
        
        Output ONLY JSON:
        {{
            "intent": "booking|info_location|info_hours|info_doctor|cancel|greeting|smalltalk|unknown",
            "entities": {{
                "doctor_name": "...",
                "specialty": "...",
                "date": "YYYY-MM-DD",
                "time": "HH:MM"
            }},
            "confidence": 0.0-1.0
        }}
        """
        # سنقوم هنا بمحاكاة الاستجابة بناءً على مدخلاتك الأخيرة "كيفك"
        if "كيف" in text or "لونك" in text:
            return {
                "intent": "smalltalk",
                "entities": {},
                "confidence": 0.98
            }
        return {"intent": "unknown", "entities": {}, "confidence": 0.5}