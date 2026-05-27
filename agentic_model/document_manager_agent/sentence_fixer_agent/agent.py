"""Agent orchestrator for Sentence Fixer tasks."""
from typing import List, Dict, Any
from .fixer import process_blocks

class SentenceFixerAgent:
    """Orchestrates sentence fixing and structural text normalization tasks.
    
    Acts as the high-level coordinator equivalent to DocumentAgent.
    """

    def __init__(self):
        pass

    def fix_context(self, pages: List[Dict[str, Any]], progress_cb = None) -> List[Dict[str, Any]]:
        """Process parsed layout pages to refine sentence/paragraph structure.
        
        1. Maintains the Table layout and figures (leaving them unchanged).
        2. Do not include the title (filters headings/titles out).
        3. Maintains paragraphs and sentences in text blocks (refining formatting).
        """
        refined_pages = []
        total_pages = len(pages)
        for idx, page in enumerate(pages, start=1):
            refined_page = dict(page)
            if 'blocks' in page:
                def local_progress_cb(step_pct, status_text):
                    if progress_cb:
                        base_percent = ((idx - 1) / total_pages) * 100
                        step_contribution = (step_pct / 100) * (100 / total_pages)
                        global_pct = int(base_percent + step_contribution)
                        progress_cb(idx, total_pages, global_pct, f"Page {idx}: {status_text}")
                        
                refined_page['blocks'] = process_blocks(page['blocks'], progress_cb=local_progress_cb)
            refined_pages.append(refined_page)
        return refined_pages
