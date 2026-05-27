"""Embeddings Agent for generating vector embeddings from fine-tuned Q&A datasets."""
import logging
from typing import List, Dict, Any

try:
    import ollama
except ImportError:
    ollama = None

logger = logging.getLogger("EmbeddingsAgent")

class EmbeddingsAgent:
    """Orchestrates generating vector representation values using Ollama snowflake-arctic-embed2:latest."""

    def __init__(self, model_name: str = "snowflake-arctic-embed2:latest"):
        self.model_name = model_name

    def check_model_available(self) -> bool:
        """Check if the requested model is available in Ollama."""
        if ollama is None:
            return False
        try:
            client = ollama.Client(timeout=600.0)
            models_info = client.list()
            available = [m.get('name', '') for m in models_info.get('models', [])]
            # Suffix/prefix match
            for m in available:
                if self.model_name in m or m in self.model_name:
                    return True
            return False
        except Exception:
            return False

    def generate_embeddings(self, qa_items: List[Dict[str, Any]], fixed_data: List[Dict[str, Any]] = None, progress_cb=None, cancel_check=None, output_dir: str = None) -> List[Dict[str, Any]]:
        """Generate a single embedding vector per page by combining the Fixed Sentence context and the Fine-Tuning Q&A dataset."""
        if not qa_items:
            return []

        # Find best model
        actual_model = self.model_name
        if not self.check_model_available():
            logger.warning(f"Model {self.model_name} is not available in local Ollama.")
            if ollama is not None:
                try:
                    client = ollama.Client(timeout=600.0)
                    available = [m.get('name', '') for m in client.list().get('models', [])]
                    for m in available:
                        if "embed" in m:
                            actual_model = m
                            logger.info(f"Falling back to embedding model: {actual_model}")
                            break
                except Exception:
                    pass

        # Normalize input: we want to map page_num -> {"fixed_text": str, "qa_pairs": List[Dict]}
        pages = {}
        is_page_objects = False
        if qa_items:
            first = qa_items[0]
            if 'fixed_blocks' in first or 'qa_pairs' in first or ('question' not in first and 'answer' not in first):
                is_page_objects = True

        if is_page_objects:
            for page in qa_items:
                page_num = page.get('page_number') or 1
                fixed_text = ""
                if 'fixed_blocks' in page and isinstance(page['fixed_blocks'], list):
                    texts = [b.get('text', '').strip() for b in page['fixed_blocks'] if b.get('text', '').strip()]
                    fixed_text = "\n\n".join(texts)
                elif 'fixed' in page and page['fixed']:
                    fixed_text = str(page['fixed']).strip()
                elif 'text' in page and page['text']:
                    fixed_text = str(page['text']).strip()

                qa_list = []
                if 'qa_pairs' in page and isinstance(page['qa_pairs'], list):
                    for qap in page['qa_pairs']:
                        if isinstance(qap, dict):
                            q = qap.get('question', '').strip()
                            a = qap.get('answer', '').strip()
                            if q and a:
                                qa_list.append({"question": q, "answer": a})

                pages[page_num] = {
                    "fixed_text": fixed_text,
                    "qa_pairs": qa_list
                }
        else:
            # List of Q&A pairs (group by page_number)
            for item in qa_items:
                page_num = item.get('page_number') or item.get('Page Number') or 1
                if page_num not in pages:
                    pages[page_num] = {
                        "fixed_text": "",
                        "qa_pairs": []
                    }
                
                q = item.get('question') or item.get('Question') or ""
                a = item.get('answer') or item.get('Answer') or ""
                if q and a:
                    pages[page_num]["qa_pairs"].append({"question": q, "answer": a})

                # Capture context from item if present
                ctx = item.get('context') or item.get('Context(fixed page)') or ""
                if ctx and not pages[page_num]["fixed_text"]:
                    pages[page_num]["fixed_text"] = ctx.strip()

            # Merge fixed_data if provided
            if fixed_data:
                for f_item in fixed_data:
                    p_num = f_item.get('page_number') or 1
                    if p_num in pages:
                        fixed_text = ""
                        if 'fixed_blocks' in f_item and isinstance(f_item['fixed_blocks'], list):
                            texts = [b.get('text', '').strip() for b in f_item['fixed_blocks'] if b.get('text', '').strip()]
                            fixed_text = "\n\n".join(texts)
                        elif 'fixed' in f_item and f_item['fixed']:
                            fixed_text = str(f_item['fixed']).strip()
                        elif 'text' in f_item and f_item['text']:
                            fixed_text = str(f_item['text']).strip()
                        
                        if fixed_text:
                            pages[p_num]["fixed_text"] = fixed_text

        sorted_pages = sorted(pages.keys())
        total_pages = len(sorted_pages)
        embedded_pages = []

        for idx, page_num in enumerate(sorted_pages, start=1):
            if cancel_check and cancel_check():
                logger.info(f"Embeddings generation cancelled at page {page_num}")
                break

            page_data = pages[page_num]
            fixed_text = page_data["fixed_text"]
            qa_pairs = page_data["qa_pairs"]

            # Prepare structured combined representation of the entire page content & Q&A
            qa_sections = []
            for item in qa_pairs:
                q = item.get('question') or ""
                a = item.get('answer') or ""
                qa_sections.append(f"Q: {q}\nA: {a}")

            # Combine page context with all generated questions and answers
            embed_text = f"Page {page_num} Fixed Sentence:\n{fixed_text}\n\nQ&A Dataset:\n" + "\n\n".join(qa_sections)

            embeddings_value = []
            if ollama is not None and embed_text.strip():
                try:
                    # Request embedding vector from Ollama for the whole page representation
                    client = ollama.Client(timeout=600.0)
                    res = client.embeddings(model=actual_model, prompt=embed_text)
                    embeddings_value = res.get('embedding', [])
                except Exception as e:
                    logger.error(f"Failed to generate embedding for Page {page_num}: {e}")
                    embeddings_value = [0.0] * 1024  # standard snowflake-arctic-embed2 dimension is 1024
            else:
                # Offline mock vector
                embeddings_value = [0.01 * (i % 100) for i in range(1024)]

            page_emb_data = {
                "page_number": page_num,
                "context": fixed_text,
                "qa_pairs": qa_pairs,
                "embeddings": embeddings_value
            }
            embedded_pages.append(page_emb_data)

            if output_dir:
                try:
                    import os
                    import json
                    os.makedirs(output_dir, exist_ok=True)
                    page_path = os.path.join(output_dir, f"page_{page_num:04d}.json")
                    with open(page_path, 'w', encoding='utf-8') as pf:
                        json.dump(page_emb_data, pf, ensure_ascii=False, indent=2)
                except Exception as e:
                    logger.warning(f"Failed to write incremental page embedding JSON: {e}")

            if progress_cb:
                percent = int((idx / total_pages) * 100)
                progress_cb(idx, total_pages, percent, f"Finished Page {page_num} ({idx} of {total_pages})...")

        return embedded_pages
