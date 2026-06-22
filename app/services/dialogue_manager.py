class DialogueManager:
    def __init__(self, tool_service):
        self.tools = tool_service

    def process(self, state, nlp_result):
        intent = nlp_result['intent']
        entities = nlp_result['entities']

        # 1. Handle Information/Inquiry Intents (Interruptions)
        if intent in ['info_location', 'info_hours', 'info_service']:
            response = self.handle_inquiry(intent, entities)
            
            # Check if we should resume a previous flow
            if state["intent_stack"]:
                prev_intent = state["intent_stack"][-1]
                response += f"\n\nبالنسبة لطلبك السابق ({prev_intent})، حابب نكمل؟"
            return response

        # 1.1 التعامل مع الدردشة الجانبية (Smalltalk)
        if intent == 'smalltalk' or intent == 'greeting':
            reply = "الحمد لله بخير، تسلم! كيف بقدر أساعدك اليوم؟" if intent == 'smalltalk' else "أهلاً بك في مستشفى الأهلي، كيف بقدر أخدمك؟"
            if state["intent_stack"]:
                reply += "\nكنا بنحجز موعد، بتحب نكمل؟"
            return reply

        # 2. Handle Booking Workflow (State Machine)
        if intent == 'booking' or state["active_intent"] == 'booking':
            return self.manage_booking_flow(state, entities)

        return "عفواً، كيف بقدر أساعدك؟"

    def manage_booking_flow(self, state, entities):
        # Update current context with found entities
        state["entities"] = self.merge_entities(state["entities"], entities)
        
        # Logic to find missing slots
        if not state["entities"].get("doctor_name"):
            return "أي طبيب حابب تحجز عنده؟"
        
        if not state["entities"].get("date"):
            return f"تمام، مع الدكتور {state['entities']['doctor_name']}. أي يوم بناسبك؟"
            
        if not state["entities"].get("time"):
            # Logic would call tools.get_available_slots here
            return "شو الوقت اللي بتفضله؟"

        return "وصلت كل البيانات، بدك أثبت الموعد؟"

    def handle_inquiry(self, intent, entities):
        if intent == 'info_hours':
            return "المستشفى بيفتح يومياً من الـ 8 صباحاً للـ 8 مساءً، والطوارئ 24 ساعة."
        if intent == 'info_location':
            return "موقعنا في الخليل، شارع الجامعة."
        return "لحظة أشوفلك المعلومة..."

    def merge_entities(self, old, new):
        for k, v in new.items():
            if v: old[k] = v
        return old