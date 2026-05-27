"""Fine Tuning Agent for generating questions and answers from fixed sentence contexts."""
import re
import json
import logging
from typing import List, Dict, Any

try:
    import ollama
except ImportError:
    ollama = None

logger = logging.getLogger("FineTuningAgent")


def _clean_json_commas(json_str: str) -> str:
    """Safely remove trailing commas before closing brackets and braces in JSON strings."""
    in_string = False
    escaped = False
    chars = list(json_str)
    n = len(chars)
    result = []
    comma_pos = -1
    for i in range(n):
        c = chars[i]
        if escaped:
            escaped = False
            result.append(c)
            continue
        if c == '\\':
            escaped = True
            result.append(c)
            continue
        if c == '"':
            in_string = not in_string
            result.append(c)
            continue
        if in_string:
            result.append(c)
            continue
        
        if c == ',':
            comma_pos = len(result)
            result.append(c)
        elif c in (']', '}'):
            if comma_pos != -1:
                only_ws = True
                for idx in range(comma_pos + 1, len(result)):
                    if not result[idx].isspace():
                        only_ws = False
                        break
                if only_ws:
                    result.pop(comma_pos)
                    comma_pos = -1
            result.append(c)
        elif not c.isspace():
            comma_pos = -1
            result.append(c)
        else:
            result.append(c)
    return "".join(result)


class FineTuningAgent:
    """Orchestrates generating Q&A pairs from the fixed sentence contexts using local Ollama."""

    def __init__(self):
        pass

    def get_preferred_model(self) -> str:
        """Always use llama2:latest model for fine tuning."""
        return "llama2:latest"

    def check_model_available(self, model_name: str) -> bool:
        """Check if the requested model is available in Ollama."""
        if ollama is None:
            return False
        try:
            client = ollama.Client(timeout=30.0)
            models_info = client.list()
            available = [m.get('name', '') for m in models_info.get('models', [])]
            for m in available:
                if model_name in m or m in model_name:
                    return True
            return False
        except Exception:
            return False
 
    def extract_page_text(self, page: Dict[str, Any]) -> str:
        """Helper to extract the full text context of a page."""
        # 1. Try fixed_blocks
        if 'fixed_blocks' in page and isinstance(page['fixed_blocks'], list):
            texts = []
            for b in page['fixed_blocks']:
                t = (b.get('text', '') or '').strip()
                if t:
                    texts.append(t)
            if texts:
                return "\n\n".join(texts)

        # 2. Try blocks
        if 'blocks' in page and isinstance(page['blocks'], list):
            texts = []
            for b in page['blocks']:
                t = (b.get('text', '') or '').strip()
                if t:
                    texts.append(t)
            if texts:
                return "\n\n".join(texts)

        # 3. Try flat 'fixed' or 'text'
        if 'fixed' in page and page['fixed']:
            return str(page['fixed']).strip()
        if 'text' in page and page['text']:
            return str(page['text']).strip()

        return ""

    def generate_qa_pairs(self, context: str, page_num: int, progress_cb=None, cancel_check=None) -> List[Dict[str, Any]]:
        """Generate 20 (at least 8) questions and answers based on the context."""
        context = context.strip()
        if len(context) < 30 or not any(c.isalpha() for c in context):
            # Fallback for empty/short contexts
            return [
                {
                    "page_number": page_num,
                    "context": context,
                    "question": f"What is the content of page {page_num}?",
                    "answer": context if context else "This page is empty or contains no readable text."
                }
                for _ in range(8)
            ]

        model = self.get_preferred_model()
        if ollama is not None and not self.check_model_available(model):
            logger.warning(f"Preferred model {model} is not available in local Ollama.")
            try:
                client = ollama.Client(timeout=30.0)
                models_info = client.list()
                available = [m.get('name', '') for m in models_info.get('models', [])]
                fallback_found = False
                for m in available:
                    m_lower = m.lower()
                    if any(x in m_lower for x in ["llama3.2", "llama3.1", "llama3", "llama", "mistral", "qwen2.5", "qwen", "gemma", "granite"]):
                        model = m
                        logger.info(f"Falling back to text model: {model}")
                        fallback_found = True
                        break
                if not fallback_found and available:
                    model = available[0]
                    logger.info(f"Falling back to first available model: {model}")
            except Exception as ex:
                logger.error(f"Error checking for fallback models: {ex}")

        logger.info(f"Using model {model} for Q&A generation on page {page_num}")

        prompt = (
            "You are an expert Q&A dataset generator. Your task is to analyze the following page text "
            "and generate 20 (at least 8) distinct, high-quality, factual question-and-answer pairs based on it.\n\n"
            f"Context:\n{context}\n\n"
            "STRICT CONSTRAINTS:\n"
            "1. You MUST generate 20 Q&A pairs, but at least 8.\n"
            "2. The questions generated MUST be based on the extracted context ONLY in the selected page.\n"
            "3. NO QUESTION should be generated that is not based on the context. Do not invent any details.\n"
            "4. Answers must be precise, concise, and 100% accurate according to the context.\n"
            "5. Format the output strictly as a JSON list of objects, where each object has exactly two keys: "
            '"question" and "answer".\n'
            "6. Do NOT wrap the JSON in markdown code blocks (such as ```json or ```). Return raw JSON only.\n"
            "7. Do NOT include any introductory or concluding text, explanations, or notes. Output ONLY the JSON list.\n\n"
            "Format example:\n"
            "[\n"
            '  {"question": "Example question?", "answer": "Example answer."}\n'
            "]"
        )

        if ollama is None:
            # Mock or offline fallback
            return self._generate_mock_qa(context, page_num)

        try:
            if progress_cb:
                progress_cb(10, "Contacting local LLM...")

            client = ollama.Client(timeout=600.0)
            response_chunks = []
            for chunk in client.generate(
                model=model,
                prompt=prompt,
                options={
                    "temperature": 0.3,
                    "top_p": 0.9,
                    "num_predict": 4096
                },
                stream=True
            ):
                if cancel_check and cancel_check():
                    raise RuntimeError("Cancellation requested.")
                response_chunks.append(chunk.get('response', ''))

            if progress_cb:
                progress_cb(60, "Parsing LLM response...")

            resp_text = "".join(response_chunks).strip()
            
            # Clean up potential markdown formatting or conversational filler
            # Strip markdown code blocks
            if resp_text.startswith("```"):
                lines = resp_text.splitlines()
                if len(lines) >= 2:
                    if lines[-1].startswith("```"):
                        resp_text = "\n".join(lines[1:-1])
                    else:
                        resp_text = "\n".join(lines[1:])
            
            # Remove any JSON prefix/suffix if present
            resp_text = re.sub(r'^.*?\[', '[', resp_text, flags=re.DOTALL)
            resp_text = re.sub(r'\].*?$', ']', resp_text, flags=re.DOTALL)

            # Clean trailing commas in JSON array/objects
            resp_text = _clean_json_commas(resp_text)

            qa_list = []
            try:
                parsed = json.loads(resp_text)
                if isinstance(parsed, list):
                    for item in parsed:
                        if isinstance(item, dict) and 'question' in item and 'answer' in item:
                            qa_list.append({
                                "page_number": page_num,
                                "context": context,
                                "question": str(item['question']).strip(),
                                "answer": str(item['answer']).strip()
                            })
                elif isinstance(parsed, dict) and 'qa_pairs' in parsed:
                    # Some models may return a dict wrapper
                    for item in parsed['qa_pairs']:
                        if isinstance(item, dict) and 'question' in item and 'answer' in item:
                            qa_list.append({
                                "page_number": page_num,
                                "context": context,
                                "question": str(item['question']).strip(),
                                "answer": str(item['answer']).strip()
                            })
                # Deduplicate by lowercase question text
                if qa_list:
                    seen = set()
                    unique_list = []
                    for qa in qa_list:
                        q = qa["question"].lower()
                        if q not in seen:
                            seen.add(q)
                            unique_list.append(qa)
                    qa_list = unique_list
                # Apply strict context filtering
                qa_list = self._filter_by_context(qa_list, context)
                
                # Ensure between 8 and 20 QA pairs
                if len(qa_list) > 20:
                    qa_list = qa_list[:20]
                while len(qa_list) < 8:
                    # Pad using heuristic generator for missing slots
                    needed = 8 - len(qa_list)
                    heuristic = self._generate_heuristic_qa(context, page_num)
                    heuristic = self._filter_by_context(heuristic, context)
                    for item in heuristic:
                        if len(qa_list) >= 8:
                            break
                        if item not in qa_list:
                            qa_list.append(item)
            except Exception as parse_err:
                logger.error(f"Failed to parse LLM JSON: {parse_err}. Raw response: {resp_text}")
                # Fallback regex extraction
                q_matches = re.findall(r'"question"\s*:\s*"([^"]+)"', resp_text)
                a_matches = re.findall(r'"answer"\s*:\s*"([^"]+)"', resp_text)
                for q, a in zip(q_matches, a_matches):
                    qa_list.append({
                        "page_number": page_num,
                        "context": context,
                        "question": q.strip(),
                        "answer": a.strip()
                    })

            # Ensure we have at least 8 questions
            qa_list = self._filter_by_context(qa_list, context)
            if not qa_list:
                logger.warning("Failed to extract any Q&A pairs from model output. Using heuristics.")
                qa_list = self._generate_heuristic_qa(context, page_num)
                qa_list = self._filter_by_context(qa_list, context)

            # Pad or slice
            if len(qa_list) > 20:
                qa_list = qa_list[:20]
            while len(qa_list) < 8:
                # Pad with duplicates or split sentences
                orig_len = len(qa_list)
                if orig_len == 0:
                    break  # If no context-based questions can be made, don't invent them.
                diff = 8 - len(qa_list)
                for k in range(diff):
                    base_item = qa_list[k % orig_len]
                    qa_list.append({
                        "page_number": page_num,
                        "context": context,
                        "question": f"Regarding the text: {base_item['question'].lower()}",
                        "answer": base_item['answer']
                    })

            return qa_list

        except Exception as e:
            logger.error(f"Ollama Q&A generation failed: {e}")
            return self._generate_heuristic_qa(context, page_num)

    def _filter_by_context(self, qa_list: List[Dict[str, Any]], context: str) -> List[Dict[str, Any]]:
        """Filter out questions that are not strictly based on the extracted context."""
        filtered = []
        if not context.strip():
            return filtered
            
        ctx_words = set(re.findall(r'\b\w+\b', context.lower()))
        stop_words = {"what", "is", "the", "a", "an", "of", "in", "to", "and", "for", "on", "it", "this", "that", "how", "why", "does", "do", "are", "can", "could", "would"}
        
        for qa in qa_list:
            q_text = str(qa.get("question", "")).lower()
            a_text = str(qa.get("answer", "")).lower()
            
            if any(phrase in a_text for phrase in ["not mentioned", "does not provide", "not specified", "is not in the context"]):
                continue
                
            q_words = set(re.findall(r'\b\w+\b', q_text)) - stop_words
            a_words = set(re.findall(r'\b\w+\b', a_text)) - stop_words
            
            q_overlap = q_words.intersection(ctx_words)
            a_overlap = a_words.intersection(ctx_words)
            
            # If neither the question nor the answer shares any significant words with the context, filter it out.
            if len(q_overlap) == 0 and len(a_overlap) == 0 and len(q_words) > 0:
                continue
                
            filtered.append(qa)
            
        return filtered

    def _generate_mock_qa(self, context: str, page_num: int) -> List[Dict[str, Any]]:
        """Offline fallback to generate 8 Q&A pairs using simple heuristics."""
        return self._generate_heuristic_qa(context, page_num)

    def _question_from_sentence(self, sentence: str) -> str:
        """Create a question from a declarative sentence without using a fixed template.
        Simple heuristic: find a verb and invert the clause.
        """
        # Basic verb list for detection
        verbs = ["is", "are", "was", "were", "has", "have", "does", "do", "can", "could", "will", "would", "should", "might", "requires", "require", "includes", "include", "contains", "contain", "states", "state", "explains", "explain", "shows", "show", "provides", "provide"]
        words = sentence.rstrip('.').split()
        # Find first verb occurrence
        for i, w in enumerate(words):
            lw = w.lower().strip('.,;:')
            if lw in verbs:
                # Build question by moving verb to front and adjusting subject
                subject = ' '.join(words[:i])
                rest = ' '.join(words[i+1:])
                # Simple transformation
                if lw in ["is", "are", "was", "were"]:
                    q = f"What {rest}?" if rest else f"What is {subject}?"
                else:
                    # turn verb to base form if ends with s
                    base = lw.rstrip('s')
                    q = f"What does {subject} {base}?" if subject else f"What does it {base}?"
                return q.capitalize()
        # Fallback generic
        return f"What is the main point of: '{sentence}'?"

    def _generate_heuristic_qa(self, context: str, page_num: int) -> List[Dict[str, Any]]:
        """Split text into sentences and formulate simple questions.
        Now uses _question_from_sentence to create more natural questions from fixed sentences.
        """
        sentences = re.split(r'(?<=\.)\s+', context)
        sentences = [s.strip() for s in sentences if len(s.strip()) > 10]
        qa_list = []
        for idx, sent in enumerate(sentences):
            if len(qa_list) >= 20:
                break
            # Generate a question from the sentence
            question = self._question_from_sentence(sent)
            answer = sent
            qa_list.append({
                "page_number": page_num,
                "context": context,
                "question": question,
                "answer": answer
            })
        # Fill remaining slots with rephrased QA if needed
        while len(qa_list) < 8:
            orig_len = len(qa_list)
            if orig_len == 0:
                break # Avoid generating questions if no context is found
            
            diff = 8 - len(qa_list)
            for k in range(diff):
                base_item = qa_list[k % orig_len]
                qa_list.append({
                    "page_number": page_num,
                    "context": context,
                    "question": f"Detail from text: {base_item['question']}",
                    "answer": base_item['answer']
                })
        return qa_list
