"""Sentence Fixer module with geometric, structural, and language-based rules."""
import re
from typing import Dict, Any, List
try:
    import ollama
except ImportError:
    ollama = None

def _normalize_text(text: str) -> str:
    if not text:
        return ""
    # Normalize various unicode whitespaces
    text = text.replace('\ufeff', '')
    text = text.replace('\u00a0', ' ')
    return text

def fix_text_block(text: str) -> str:
    """Refine sentences and paragraphs in a single body text block.
    
    1. Re-joins hyphenated words split by line breaks.
    2. Re-joins mid-sentence line breaks when a sentence continues.
    3. Cleans punctuation spacing and duplicate spacing.
    4. Preserves paragraphs separated by double newlines.
    5. Detects and preserves table rows and list items.
    """
    text = _normalize_text(text)
    if not text.strip():
        return ""

    # Check if this text block is a table or contains a table structure (e.g. lots of '|' or markdown table rows)
    # If it is entirely a table, preserve it intact to maintain the table layout!
    lines = re.split(r'\r?\n', text)
    table_lines = [l for l in lines if l.strip().startswith('|')]
    if len(table_lines) >= len(lines) * 0.5 and len(lines) > 1:
        # It's a table! Maintain the Table layout exactly
        return text

    # Step 1: Re-join hyphenated words split by line breaks: e.g. "deve- \n lopment" -> "development"
    fixed = re.sub(r'(\b\w+)-\s*\r?\n\s*(\w+\b)', r'\1\2', text)

    lines = re.split(r'\r?\n', fixed)
    joined_lines = []
    current_line = ""

    continuation_words = {
        'and', 'or', 'but', 'to', 'of', 'in', 'for', 'with', 'on', 'at', 'by', 
        'from', 'as', 'that', 'which', 'who', 'whom', 'whose', 'because', 'although',
        'if', 'since', 'unless', 'until', 'while', 'so', 'yet', 'is', 'are', 'was', 
        'were', 'the', 'a', 'an', 'has', 'have', 'had', 'been', 'will', 'shall', 
        'should', 'would', 'could', 'may', 'might', 'must', 'their', 'our', 'his', 
        'her', 'its'
    }

    list_marker_pattern = re.compile(r'^(\d+[\.\)]|[a-zA-Z][\.\)]|[\*\-\•])\s')

    for idx, line in enumerate(lines):
        trimmed = line.strip()
        if trimmed == '':
            if current_line:
                joined_lines.append(current_line)
                current_line = ""
            joined_lines.append('')
            continue

        # If the line is a table row, keep it separate and don't merge!
        if trimmed.startswith('|'):
            if current_line:
                joined_lines.append(current_line)
                current_line = ""
            joined_lines.append(trimmed)
            continue

        if not current_line:
            current_line = trimmed
        else:
            # If current line is a table row, do not merge with next
            if current_line.startswith('|'):
                joined_lines.append(current_line)
                current_line = trimmed
                continue

            first_word_match = re.match(r'^\w+', trimmed)
            first_word = first_word_match.group(0).lower() if first_word_match else ""
            first_char = trimmed[0] if trimmed else ""

            # Use punctuation (".", "!", "?") as end of the sentence
            is_terminated = bool(re.search(r'[.!?]["\')]*\s*$', current_line))
            is_next_list = list_marker_pattern.match(trimmed) is not None

            # Check if current line ends with a continuation word
            words_in_current = current_line.split()
            last_word = words_in_current[-1].lower().strip(".,!?;:()[]\"'") if words_in_current else ""
            ends_with_continuation = last_word in continuation_words

            # If the next line starts with a lowercase letter, it is a connected statement
            next_first_char_is_lower = trimmed and trimmed[0].islower()

            # Heuristics to identify headers/labels (short lines starting with uppercase and not ending in punctuation)
            # If the next line starts with a lowercase letter, this line is a connected statement (not a header)
            is_header_or_label = len(current_line) < 35 and not is_terminated and not ends_with_continuation and current_line[0].isupper() and not next_first_char_is_lower

            # Merge/join the possible connected statement in the sentence
            if not is_terminated and not is_next_list and not is_header_or_label:
                if current_line.endswith('-'):
                    current_line = current_line[:-1].rstrip() + trimmed
                else:
                    current_line += " " + trimmed
            else:
                joined_lines.append(current_line)
                current_line = trimmed

    if current_line:
        joined_lines.append(current_line)

    # Step 3: Normalize double newlines for paragraph separation
    paragraphs = []
    for line in joined_lines:
        if line == '':
            continue
        if line.startswith('|'):
            paragraphs.append(line)
            continue
        # Fix spacing around punctuation
        line_fixed = re.sub(r'\s+([.,!?;:])', r'\1', line)
        line_fixed = re.sub(r'([.,!?;:])(?=[a-zA-Z])', r'\1 ', line_fixed)
        # Clean up duplicate spacing
        line_fixed = re.sub(r'[ \t]+', ' ', line_fixed)
        paragraphs.append(line_fixed.strip())

    # Return with paragraph boundaries preserved (consecutive table lines have single newlines, others double newlines)
    output_parts = []
    in_table = False
    for p in paragraphs:
        if p.startswith('|'):
            if in_table:
                output_parts.append("\n" + p)
            else:
                output_parts.append("\n\n" + p)
                in_table = True
        else:
            output_parts.append("\n\n" + p)
            in_table = False

    return "".join(output_parts).strip()

def llm_fix_text(text: str) -> str:
    """Arranges the sentence/paragraph structure using local Ollama model to guarantee:
    1. Perfect paragraph flow, merging and joining statements correctly.
    2. Strictly no added periods or other new punctuation.
    3. Strictly no casing modifications.
    """
    if not text.strip() or ollama is None:
        return text

    # Use only qwen2.5:3b model
    model_to_use = "qwen2.5:3b"

    prompt = (
        "You are a strict text layout and sentence rejoining assistant. Your only task is to "
        "rejoin split words and combine broken mid-sentence or mid-paragraph line/block transitions "
        "so that sentences are complete and continuous.\n\n"
        "STRICT CONSTRAINTS:\n"
        "1. Use punctuation marks ('.', '!', '?') as the end of sentences. Merge or join any connected "
        "statements that are part of the same continuous sentence. Do NOT split a single sentence "
        "with newlines or paragraph breaks.\n"
        "2. Do NOT add any new punctuation marks (especially do NOT add any periods '.' at the end of sentences). "
        "Preserve existing punctuation exactly as-is.\n"
        "3. Do NOT change, correct, or adjust the letter casing of any words (keep uppercase, lowercase, title case "
        "exactly as they appear in the original text).\n"
        "4. Do NOT rewrite, rephrase, correct grammar, or alter any words. Every word in the output must appear "
        "exactly as in the original input, only with spacing and line splits corrected.\n"
        "5. Do NOT output any introductory remarks, explanations, notes, or conversational filler. Output ONLY the finalized text.\n\n"
        "EXAMPLES:\n"
        "Input:\nwe are Proud to pre-\nsent this amazing project\nOutput:\nwe are Proud to present this amazing project\n\n"
        "Input:\nIs this a test? Yes it is! This is completely finished.\n\nWe are done\nOutput:\nIs this a test? Yes it is! This is completely finished.\n\nWe are done\n\n"
        f"Input to process:\n{text}\nOutput:\n"
    )

    try:
        response = ollama.generate(model=model_to_use, prompt=prompt, options={
            "temperature": 0.0,
            "top_p": 0.1,
            "num_predict": len(text) * 2 + 100
        })
        fixed_text = response.get('response', '').strip()
        # Let's clean up any leading/trailing assistant boilerplate or markdown code blocks
        if fixed_text.startswith("```"):
            lines = fixed_text.splitlines()
            if len(lines) >= 2:
                fixed_text = "\n".join(lines[1:-1]) if lines[-1].startswith("```") else "\n".join(lines[1:])
        
        # Strip common assistant filler introductions
        filler_patterns = [
            r'^here is the text with (spacing|splits|hyphens|line breaks) corrected[:\s\-\n]*',
            r'^based on the (input|constraints)[,\s]*here is the (corrected\s+text|corrected\s+version|corrected|finalized|rejoined|output|text|version)[:\s\-\n]*',
            r'^(here is|here\'s|below is|the following is|this is) (the\s+)?(corrected\s+text|finalized\s+text|rejoined\s+text|corrected|finalized|rejoined|output|text|version|result)[:\s\-\n]*'
        ]
        for pat in filler_patterns:
            fixed_text = re.sub(pat, '', fixed_text, flags=re.IGNORECASE)

        # Remove repeated examples if model outputted them but they are not in the original text
        for item in [
            "Is this a test? Yes it is! This is completely finished.",
            "We are done",
            "we are Proud to present this amazing project"
        ]:
            if item in fixed_text and item not in text:
                fixed_text = fixed_text.replace(item, "")

        # If the model outputted introductory/conversational filler or repeated the prompt/example output
        for filler in ["Input:", "Output:", "Input to process:"]:
            if filler in fixed_text and filler not in text:
                fixed_text = fixed_text.replace(filler, "")

        fixed_text = re.sub(r'\n\s*\n+', '\n\n', fixed_text).strip()
            
        return fixed_text
    except Exception:
        # Fallback to local deterministic correction if LLM fails
        pass

    return text

def is_title(block: Dict[str, Any]) -> bool:
    """Heuristic to determine if a block is a Title/Heading.
    
    Checks 'kind', bold styling, and text features.
    """
    kind = block.get('kind', '').lower()
    text = (block.get('text', '') or '').strip()
    
    if kind == 'heading':
        return True
    
    if block.get('is_bold') is True:
        return True
        
    if block.get('ai', {}).get('is_heading') is True:
        return True

    # Custom text heuristic for document titles
    if text:
        words = text.split()
        if len(words) <= 10:
            # High ratio of capitalized words
            cap_words = [w for w in words if w and w[0].isupper()]
            if len(cap_words) >= len(words) * 0.7:
                return True
                
    return False


def is_table_or_figure(block: Dict[str, Any]) -> bool:
    """Determine if a block is a Table or Figure."""
    kind = block.get('kind', '').lower()
    text = (block.get('text', '') or '').strip()
    
    if kind in ('table', 'figure'):
        return True
        
    # Check if text contains markdown table rows
    if text:
        lines = re.split(r'\r?\n', text)
        table_lines = [l for l in lines if l.strip().startswith('|')]
        if len(table_lines) >= len(lines) * 0.5 and len(lines) > 1:
            return True
            
    return False


def process_blocks(blocks: List[Dict[str, Any]], progress_cb = None) -> List[Dict[str, Any]]:
    """Process a list of blocks on the page as a single page-wide entity:
    
    1. Pre-segments and merges adjacent normal text blocks on the page
       if they belong to a continuous sentence or paragraph flow.
    2. Maintains the Table layout and figures (leaves unchanged).
    3. Retains titles.
    4. Maintains paragraphs and sentences (cleans body text).
    """
    if not blocks:
        return []

    if progress_cb:
        progress_cb(10, "Merging layout statements...")

    # Step 1: Pre-process blocks on the page to join/merge adjacent normal text blocks
    merged_blocks = []
    i = 0
    while i < len(blocks):
        b = blocks[i]
        
        # If this block is a table, figure, or title, don't merge it, just append
        if is_table_or_figure(b) or is_title(b):
            merged_blocks.append(dict(b))
            i += 1
            continue
        
        # This is a normal text block. Let's see if we can merge it with subsequent normal text blocks
        current_block = dict(b)
        current_text = (current_block.get('text', '') or '').strip()
        
        # Look ahead to see if next blocks are also normal text and should be merged
        while i + 1 < len(blocks):
            next_b = blocks[i + 1]
            
            # If the next block is a table/figure or title, we cannot merge across it
            if is_table_or_figure(next_b) or is_title(next_b):
                break
                
            next_text = (next_b.get('text', '') or '').strip()
            if not next_text:
                i += 1
                continue
            
            # Determine spacing based on sentence termination heuristic
            is_terminated = bool(re.search(r'[.!?]["\')]*\s*$', current_text))
            last_char = current_text[-1] if current_text else ''
            if is_terminated:
                # Scanned period/punctuation symbol as basis of identifying a complete sentence: start new paragraph/sentence
                current_text = current_text + "\n\n" + next_text
            elif last_char == '-':
                # Word split across layout boundary: strip the hyphen and merge directly
                current_text = current_text[:-1].rstrip() + next_text
            else:
                # Merge/join the statement as part of the same continuous sentence
                current_text = current_text + " " + next_text
            
            current_block['text'] = current_text
            i += 1  # Consume the merged block
            
        merged_blocks.append(current_block)
        i += 1

    if progress_cb:
        progress_cb(40, "Refining sentences with local LLM...")

    # Step 2: Refine and format the text content for the entire page's merged blocks
    refined_blocks = []
    for b in merged_blocks:
        if is_table_or_figure(b) or is_title(b):
            # Maintain Table layout, figures, and titles exactly as is
            refined_blocks.append(b)
        else:
            # Preserve other blocks, processing text for non-table/figure/title blocks
            refined_block = dict(b)
            # First normalize text structure then send to LLM/Clean-up for formatting
            structured = fix_text_block(b.get('text', ''))
            refined_block['text'] = llm_fix_text(structured)
            refined_blocks.append(refined_block)

    if progress_cb:
        progress_cb(80, "Finalizing page arrangement...")
            
    combined_text = "\n\n".join([b.get('text', '').strip() for b in refined_blocks if b.get('text', '').strip()])
    return [{
        'kind': 'Text',
        'text': combined_text
    }]
