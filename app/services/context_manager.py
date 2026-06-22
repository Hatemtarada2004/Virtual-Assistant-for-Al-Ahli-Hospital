class ContextManager:
    def __init__(self):
        self.default_state = {
            "active_intent": None,
            "intent_stack": [],
            "entities": {},
            "status": "IDLE"
        }

    def get_state(self, session_id, database_service):
        # In a real app, fetch from Redis/Session
        return database_service.get_session(session_id) or self.default_state.copy()

    def update_entities(self, current_entities, new_entities):
        """Merges new extracted entities into the existing context."""
        for key, value in new_entities.items():
            if value:
                current_entities[key] = value
        return current_entities

    def handle_intent_switch(self, state, new_intent):
        """
        Logic for Intent Switching:
        If user is booking and asks for 'location', we push 'booking' to stack,
        process 'location', then the dialogue manager will decide to resume.
        """
        if state["active_intent"] and state["active_intent"] != new_intent:
            # Prevent duplicates in stack
            if state["active_intent"] not in state["intent_stack"]:
                state["intent_stack"].append(state["active_intent"])
        
        state["active_intent"] = new_intent
        return state

    def pop_intent(self, state):
        """Returns to the previous intent if the stack isn't empty."""
        if state["intent_stack"]:
            state["active_intent"] = state["intent_stack"].pop()
            return True
        return False