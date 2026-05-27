"""Agent orchestrator for document parsing and model-driven tasks."""
import os
import logging
import re
from typing import Optional, Dict, Any, List

from .parser import PDFAgent


class BaseProvider:
    def summarize(self, text: str, max_tokens: int = 512) -> str:
        raise NotImplementedError()


class LocalProvider(BaseProvider):
    """Simple fallback summarizer that picks top sentences."""
    def summarize(self, text: str, max_tokens: int = 512) -> str:
        if not text:
            return ""
        # normalize whitespace (collapse newlines/tabs/spaces)
        text = re.sub(r"\s+", " ", text).strip()
        # Extractive summarization by sentence scoring (word-frequency)
        # Split into sentences using punctuation heuristics
        sentences = re.split(r'(?<=[.!?])\s+', text)
        if len(sentences) == 1:
            # fallback: simple truncate
            return text[:max_tokens].strip()

        # small stopword list to ignore common words
        stopwords = {
            'the','and','or','to','of','in','a','an','is','are','be','for','on','with',
            'that','this','it','as','by','from','at','was','were','which','have','has',
            'had','but','not','will','can','its','their','they','we','our','us'
        }

        # Build word frequency
        freq: Dict[str, int] = {}
        for w in re.findall(r"[A-Za-z0-9']+", text.lower()):
            if w in stopwords or len(w) < 2:
                continue
            freq[w] = freq.get(w, 0) + 1

        if not freq:
            return text[:max_tokens].strip()

        maxf = max(freq.values())
        for k in freq:
            freq[k] = freq[k] / float(maxf)

        # Score sentences
        sent_scores: List[float] = []
        sent_tokens: List[List[str]] = []
        for s in sentences:
            words = [w for w in re.findall(r"[A-Za-z0-9']+", s.lower()) if w not in stopwords]
            sent_tokens.append(words)
            score = sum(freq.get(w, 0.0) for w in words)
            # penalize very short sentences
            if len(s) < 30:
                score *= 0.6
            sent_scores.append(score)

        # select top sentences by score until reaching max_chars, preserve order
        indexed = list(enumerate(sentences))
        ranked_idxs = sorted(range(len(sentences)), key=lambda i: sent_scores[i], reverse=True)

        selected = set()
        total_chars = 0
        for i in ranked_idxs:
            s = sentences[i].strip()
            if not s:
                continue
            if total_chars + len(s) + 1 > max_tokens:
                continue
            selected.add(i)
            total_chars += len(s) + 1
            if total_chars >= max_tokens:
                break

        if not selected:
            # fallback to first sentence(s)
            out = []
            total = 0
            for s in sentences:
                s = s.strip()
                if not s:
                    continue
                out.append(s)
                total += len(s) + 1
                if total >= max_tokens:
                    break
            return re.sub(r"\s+", " ", ' '.join(out)).strip()

        # assemble selected sentences in original order
        out_list = [sentences[i].strip() for i in range(len(sentences)) if i in selected]
        summary = ' '.join(out_list)
        return re.sub(r"\s+", " ", summary).strip()


class DocumentAgent:
    """Orchestrates parsing and model tasks for a PDF document."""

    def __init__(self, provider: Optional[BaseProvider] = None):
        self.provider = provider or LocalProvider()
        self.parser_agent = PDFAgent()

    def parse(self, pdf_path: str, progress_callback=None, cancel_check=None) -> List[Dict[str, Any]]:
        return self.parser_agent.parse(pdf_path, progress_callback=progress_callback, cancel_check=cancel_check)

    def summarize(self, pdf_path: str, max_tokens: int = 1024) -> str:
        pages = self.parse(pdf_path)
        parts = []
        for page in pages:
            for el in page.get('elements', []):
                if el.get('text'):
                    parts.append(el['text'])
        full_text = ' '.join(parts)
        return self.provider.summarize(full_text, max_tokens=max_tokens)
