"""Lightweight PDF parser using pdfplumber (fallback-only, minimal dependencies).

This module provides a small PDFAgent that extracts text blocks and simple
table regions and returns serializable page layouts.
"""
from dataclasses import dataclass, asdict
from pathlib import Path
from typing import List, Dict, Any, Optional
import pdfplumber
import logging
import re


@dataclass
class LayoutElement:
    type: str
    block_type: str
    coordinates: Dict[str, float]
    confidence: float
    text: Optional[str] = None
    page_number: int = 0
    is_bold: bool = False


@dataclass
class PageLayout:
    page_number: int
    elements: List[LayoutElement]
    width: int
    height: int


def _normalize_text(value: str) -> str:
    if not value:
        return ""
    text = value.replace('\ufeff', '')
    text = text.replace('\u00a0', ' ')
    text = re.sub(r"\s+", " ", text)
    return text.strip()


def _make_line_entry(line_words: List[Dict[str, Any]], index: int) -> Dict[str, Any]:
    text = " ".join(_normalize_text(w.get('text', '')) for w in line_words).strip()
    x0 = min(float(w.get('x0', 0.0)) for w in line_words)
    x1 = max(float(w.get('x1', 0.0)) for w in line_words)
    top = min(float(w.get('top', 0.0)) for w in line_words)
    bottom = max(float(w.get('bottom', 0.0)) for w in line_words)
    return {
        "index": index,
        "text": text,
        "x0": x0,
        "x1": x1,
        "top": top,
        "bottom": bottom,
    }


def _group_words_into_lines(words: List[Dict[str, Any]], y_tolerance: float = 5.0) -> List[Dict[str, Any]]:
    if not words:
        return []
    sorted_words = sorted(words, key=lambda w: (float(w.get('top', 0.0)), float(w.get('x0', 0.0))))
    lines = []
    current = []
    current_y = None
    for w in sorted_words:
        y = float(w.get('top', 0.0))
        if current_y is None or abs(y - current_y) <= y_tolerance:
            current.append(w)
            current_y = y if current_y is None else (current_y + y) / 2.0
        else:
            lines.append(_make_line_entry(current, len(lines)))
            current = [w]
            current_y = y
    if current:
        lines.append(_make_line_entry(current, len(lines)))
    return lines


def _rows_to_markdown(rows: List[List[str]]) -> Optional[str]:
    if not rows:
        return None
    col_count = max(len(r) for r in rows)
    if col_count < 2:
        return None
    normalized = [row + [""] * (col_count - len(row)) for row in rows]
    header = normalized[0]
    if not any(header):
        header = [f"col_{i+1}" for i in range(col_count)]
        normalized[0] = header
    lines = ["| " + " | ".join(header) + " |", "|" + "|".join(["---"] * col_count) + "|"]
    for row in normalized[1:]:
        lines.append("| " + " | ".join(row) + " |")
    return "\n".join(lines)


def _extract_table_with_spans(t) -> List[List[str]]:
    rows = t.rows
    cols = t.columns
    cells = t.cells
    
    if not rows or not cols:
        return []

    # Map each grid cell (r_idx, c_idx) to the physical cell containing its center
    grid = {}
    for r_idx, r in enumerate(rows):
        for c_idx, c in enumerate(cols):
            cx = (c.bbox[0] + c.bbox[2]) / 2.0
            cy = (r.bbox[1] + r.bbox[3]) / 2.0
            
            found = None
            for cell_idx, cell in enumerate(cells):
                x0, top, x1, bottom = cell
                if x0 - 1.5 <= cx <= x1 + 1.5 and top - 1.5 <= cy <= bottom + 1.5:
                    found = cell_idx
                    break
            grid[(r_idx, c_idx)] = found

    # Find the top-left coordinate for each physical cell in the grid
    top_lefts = {}
    for (r, c), cell_idx in grid.items():
        if cell_idx is not None:
            if cell_idx not in top_lefts:
                top_lefts[cell_idx] = (r, c)
            else:
                r_prev, c_prev = top_lefts[cell_idx]
                if r < r_prev or (r == r_prev and c < c_prev):
                    top_lefts[cell_idx] = (r, c)

    # Get the raw text grid from pdfplumber
    raw_extracted = t.extract() or []
    
    # Fill in the output grid with text and merge indicators
    grid_text = []
    for r_idx in range(len(rows)):
        row_text = []
        for c_idx in range(len(cols)):
            cell_idx = grid.get((r_idx, c_idx))
            if cell_idx is None:
                row_text.append("")
                continue
                
            r_tl, c_tl = top_lefts[cell_idx]
            if r_idx == r_tl and c_idx == c_tl:
                val = ""
                if r_idx < len(raw_extracted) and c_idx < len(raw_extracted[r_idx]):
                    val = raw_extracted[r_idx][c_idx] or ""
                row_text.append(_normalize_text(val))
            else:
                is_row_merge = r_idx > r_tl
                is_col_merge = c_idx > c_tl
                if is_row_merge and is_col_merge:
                    row_text.append("↖")
                elif is_row_merge:
                    row_text.append("↑")
                else:
                    row_text.append("←")
        grid_text.append(row_text)
        
    return grid_text


class PDFAgent:
    """Minimal parser agent using pdfplumber only."""

    def __init__(self):
        pass

    def parse(self, pdf_path: str, progress_callback=None, cancel_check=None) -> List[Dict[str, Any]]:
        pdf_path = Path(pdf_path)
        if not pdf_path.exists():
            raise FileNotFoundError(str(pdf_path))

        pages_out: List[Dict[str, Any]] = []
        try:
            with pdfplumber.open(str(pdf_path)) as pdf:
                total_pages = len(pdf.pages)
                for page_num, page in enumerate(pdf.pages, start=1):
                    # Check for cancellation before processing each page
                    if cancel_check and cancel_check():
                        logging.info("Parsing cancelled at page %d/%d", page_num, total_pages)
                        break
                    if progress_callback:
                        progress_callback(page_num, total_pages)
                    words = page.extract_words(extra_attrs=["fontname"]) or []
                    lines = _group_words_into_lines(words)
                    elements: List[LayoutElement] = []
                    table_bboxes = []

                    # simple table extraction
                    try:
                        tables = page.find_tables() or []
                        for t in tables:
                            rows = _extract_table_with_spans(t)
                            md = _rows_to_markdown(rows)
                            if md:
                                bbox = t.bbox
                                table_bboxes.append(bbox)
                                elements.append(LayoutElement(
                                    type="Table",
                                    block_type="Table",
                                    coordinates={"x_1": float(bbox[0]), "y_1": float(bbox[1]), "x_2": float(bbox[2]), "y_2": float(bbox[3])},
                                    confidence=0.8,
                                    text=md,
                                    page_number=page_num
                                ))
                    except Exception:
                        logging.debug("table extraction failed on page %s", page_num)

                    # add text words as Text elements
                    for w in words:
                        x0 = float(w.get('x0', 0.0))
                        top = float(w.get('top', 0.0))
                        x1 = float(w.get('x1', 0.0))
                        bottom = float(w.get('bottom', 0.0))
                        fontname = str(w.get('fontname', '')).lower()
                        is_bold = 'bold' in fontname

                        # skip words that fall inside any table's bounding box
                        cx = (x0 + x1) / 2.0
                        cy = (top + bottom) / 2.0
                        in_table = False
                        for (tx0, ty0, tx1, ty1) in table_bboxes:
                            if tx0 <= cx <= tx1 and ty0 <= cy <= ty1:
                                in_table = True
                                break
                        
                        if in_table:
                            continue

                        elements.append(LayoutElement(
                            type="Text",
                            block_type="Text",
                            coordinates={
                                'x_1': x0,
                                'y_1': top,
                                'x_2': x1,
                                'y_2': bottom
                            },
                            confidence=1.0,
                            text=_normalize_text(w.get('text', '')),
                            page_number=page_num,
                            is_bold=is_bold
                        ))

                    pages_out.append({
                        'page_number': page_num,
                        'width': int(page.width),
                        'height': int(page.height),
                        'elements': [asdict(e) for e in elements]
                    })
        except Exception as e:
            logging.exception('Error parsing PDF: %s', e)

        return pages_out
