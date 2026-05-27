"""CLI entrypoint for the document agent."""
import os
import json
import sys
import logging
import argparse

from .agent import DocumentAgent
import re


def _transform_page(p, page_index):
    """Transform a raw parsed page dict into the legacy blocks format expected by the PHP app."""
    blocks = []
    for el in p.get('elements', []):
        coords = el.get('coordinates', {}) or {}
        x1 = coords.get('x_1') if coords.get('x_1') is not None else coords.get('x0', 0)
        y1 = coords.get('y_1') if coords.get('y_1') is not None else coords.get('top', 0)
        x2 = coords.get('x_2') if coords.get('x_2') is not None else coords.get('x1', x1)
        y2 = coords.get('y_2') if coords.get('y_2') is not None else coords.get('bottom', y1)
        try:
            x = float(x1)
            y = float(y1)
            x2f = float(x2)
            y2f = float(y2)
            w = x2f - x
            h = y2f - y
        except Exception:
            x = y = w = h = 0.0

        blocks.append({
            'kind': el.get('block_type', el.get('type', 'Text')),
            'x': float(x),
            'y': float(y),
            'w': float(w),
            'h': float(h),
            'text': (el.get('text') or '').strip(),
            'is_bold': el.get('is_bold', False)
        })

    # Sort blocks by vertical position (top/y) then horizontal (x)
    blocks_sorted = sorted(blocks, key=lambda b: (round(b.get('y', 0), 2), round(b.get('x', 0), 2)))

    # Group nearby text blocks on the same line to approximate reading order
    grouped = []
    line_tol = 6.0
    current = None
    for b in blocks_sorted:
        if b.get('kind') != 'Text':
            if current:
                grouped.append(current)
                current = None
            grouped.append(b)
            continue

        if current is None:
            current = b.copy()
            continue

        if abs(b['y'] - current['y']) <= line_tol:
            current['text'] = (current.get('text', '') + ' ' + b.get('text', '')).strip()
            x_min = min(current.get('x', 0), b.get('x', 0))
            x_max = max(current.get('x', 0) + current.get('w', 0), b.get('x', 0) + b.get('w', 0))
            current['x'] = float(x_min)
            current['w'] = float(x_max - x_min)
            current['h'] = float(max(current.get('h', 0), b.get('h', 0)))
            current['y'] = float(min(current.get('y', 0), b.get('y', 0)))
            current['is_bold'] = current.get('is_bold', False) or b.get('is_bold', False)
        else:
            grouped.append(current)
            current = b.copy()

    if current:
        grouped.append(current)

    # Ensure grouped order is by y then x
    grouped = sorted(grouped, key=lambda b: (round(b.get('y', 0), 2), round(b.get('x', 0), 2)))

    # Merge adjacent lines into paragraphs for denser output
    paragraphs = []
    para_tol = 18.0
    para_x_tol = 40.0
    current_para = None
    for item in grouped:
        if item.get('kind') != 'Text':
            if current_para:
                paragraphs.append(current_para)
                current_para = None
            paragraphs.append(item)
            continue

        if current_para is None:
            current_para = item.copy()
            current_para['_last_y'] = item.get('y', 0)
            continue

        line_y = item.get('y', 0)
        
        txt_prev = (current_para.get('text') or '').strip()
        is_title_prev = current_para.get('is_bold', False)
        if not is_title_prev and len(txt_prev) <= 80:
            words_cap = re.findall(r"[A-Z][a-z]+|[A-Z]{2,}", txt_prev)
            if len(words_cap) >= max(1, len(txt_prev.split())//3):
                is_title_prev = True
                
        txt_curr = (item.get('text') or '').strip()
        is_title_curr = item.get('is_bold', False)
        if not is_title_curr and len(txt_curr) <= 80:
            words_cap_curr = re.findall(r"[A-Z][a-z]+|[A-Z]{2,}", txt_curr)
            if len(words_cap_curr) >= max(1, len(txt_curr.split())//3):
                is_title_curr = True

        terminate_prev = txt_prev.endswith('.')
        if is_title_prev != is_title_curr:
            terminate_prev = True

        if not terminate_prev and abs(line_y - current_para['_last_y']) <= para_tol and abs(item.get('x', 0) - current_para.get('x', 0)) <= para_x_tol:
            current_para['text'] = (current_para.get('text', '') + ' ' + item.get('text', '')).strip()
            x_min = min(current_para.get('x', 0), item.get('x', 0))
            x_max = max(current_para.get('x', 0) + current_para.get('w', 0), item.get('x', 0) + item.get('w', 0))
            current_para['x'] = float(x_min)
            current_para['w'] = float(x_max - x_min)
            current_para['h'] = float(max(current_para.get('h', 0), item.get('h', 0)))
            current_para['is_bold'] = current_para.get('is_bold', False) or item.get('is_bold', False)
            current_para['_last_y'] = line_y
        else:
            if current_para:
                current_para.pop('_last_y', None)
                paragraphs.append(current_para)
            current_para = item.copy()
            current_para['_last_y'] = line_y

    if current_para:
        current_para.pop('_last_y', None)
        paragraphs.append(current_para)

    # Strip layout classification keys (is_bold, ai, is_heading) from output blocks
    annotated = []
    for item in paragraphs:
        new_item = dict(item)
        new_item.pop('is_bold', None)
        annotated.append(new_item)

    return {
        'page_number': int(p.get('page_number', 0)),
        'source_page': int(p.get('page_number', 0)),
        'width': int(p.get('width', 0)),
        'height': int(p.get('height', 0)),
        'blocks': annotated,
    }


def main(argv=None):
    # Reconfigure stdout and stderr to handle UTF-8 printing in Windows terminal
    import sys
    try:
        sys.stdout.reconfigure(encoding='utf-8')
        sys.stderr.reconfigure(encoding='utf-8')
    except Exception:
        pass

    parser = argparse.ArgumentParser(prog="document-agent", description="Document Agent CLI")
    parser.add_argument("pdf", help="Path to PDF file to process")
    parser.add_argument("--summary", action="store_true", help="Produce a summary")
    parser.add_argument("--no-summary", action="store_true", help="Do not produce a summary (compat)")
    parser.add_argument("-o", "--out", default=None, help="Output JSON file for extracted context")
    parser.add_argument("--context-output", default=None, help="Write parsed context JSON to this path (compat)")
    parser.add_argument("--max-tokens", type=int, default=1024, help="Max tokens/characters for summary heuristics")
    parser.add_argument("--progress-file", default=None, help="File to write progress updates to")
    parser.add_argument("--output-dir", default=None, help="Directory to write per-page JSON files incrementally")
    parser.add_argument("--cancel-file", default=None, help="Path to cancel flag file; if it exists, parsing stops")
    parser.add_argument("--pid-file", default=None, help="Path to write the process ID (PID) to")
    args = parser.parse_args(argv)

    if args.pid_file:
        try:
            with open(args.pid_file, 'w', encoding='utf-8') as f:
                f.write(str(os.getpid()))
            import atexit
            def _clean_pid():
                try:
                    if os.path.exists(args.pid_file):
                        os.remove(args.pid_file)
                except Exception:
                    pass
            atexit.register(_clean_pid)
        except Exception as e:
            logging.warning(f"Failed to write PID file {args.pid_file}: {e}")

    logging.basicConfig(level=logging.INFO)
    agent = DocumentAgent()

    # Cancel check: returns True if the cancel flag file exists
    def cancel_check():
        if args.cancel_file and os.path.isfile(args.cancel_file):
            return True
        return False

    # Track pages written incrementally for output-dir mode
    all_legacy_pages = []
    was_cancelled = False

    def progress_callback(page_num, total_pages):
        if args.progress_file:
            try:
                with open(args.progress_file, 'w', encoding='utf-8') as pf:
                    json.dump({
                        'page': page_num,
                        'total': total_pages,
                        'percent': int((page_num / total_pages) * 100)
                    }, pf)
            except Exception:
                pass

    # Always parse to extract pages and elements
    parsed = agent.parse(args.pdf, progress_callback=progress_callback, cancel_check=cancel_check)

    # Check if we were cancelled
    was_cancelled = cancel_check()

    produce_summary = bool(args.summary) and not bool(args.no_summary)

    # 1. Extract all text parts globally once for global summary
    all_text_parts = []
    for p in parsed:
        for el in p.get('elements', []):
            if el.get('text'):
                all_text_parts.append(el['text'])
    full_text = ' '.join(all_text_parts)

    # 2. Generate global summary once
    summary_text = ''
    try:
        summary_text = agent.provider.summarize(full_text, max_tokens=300)
    except Exception as e:
        logging.warning(f"Failed to generate summary: {e}")
        summary_text = ''

    # 3. Transform parsed format to the page-and-blocks structure expected by PHP application
    legacy_pages = []
    for idx, p in enumerate(parsed):
        legacy_page = _transform_page(p, idx)
        legacy_pages.append(legacy_page)

        # Write per-page JSON incrementally if output-dir is set
        if args.output_dir:
            try:
                os.makedirs(args.output_dir, exist_ok=True)
                page_num = legacy_page['page_number']
                page_path = os.path.join(args.output_dir, f'page_{page_num:04d}.json')
                with open(page_path, 'w', encoding='utf-8') as pf:
                    json.dump(legacy_page, pf, ensure_ascii=False, indent=2)
            except Exception as e:
                logging.warning(f"Failed to write page {page_num} JSON: {e}")

    # Write output to the single requested JSON file
    output_written = False

    if args.out:
        try:
            with open(args.out, 'w', encoding='utf-8') as f:
                json.dump(legacy_pages, f, ensure_ascii=False, indent=2)
            print(f"Wrote context to {args.out}")
            output_written = True
        except Exception as e:
            print(f"Failed to write context to {args.out}: {e}", file=sys.stderr)

    if args.context_output:
        try:
            with open(args.context_output, 'w', encoding='utf-8') as cf:
                json.dump(legacy_pages, cf, ensure_ascii=False, indent=2)
            print(f"Wrote context to {args.context_output}")
            output_written = True
        except Exception as e:
            print(f"Failed to write context to {args.context_output}: {e}", file=sys.stderr)

    # Print results to stdout if no output files were successfully written
    if not output_written:
        if produce_summary:
            print(summary_text)
        else:
            print(json.dumps(legacy_pages, ensure_ascii=False, indent=2))

    # Write final status to progress file
    if args.progress_file:
        try:
            status = 'cancelled' if was_cancelled else 'completed'
            total = len(parsed)
            with open(args.progress_file, 'w', encoding='utf-8') as pf:
                json.dump({
                    'page': len(legacy_pages),
                    'total': total,
                    'percent': 100 if not was_cancelled else int((len(legacy_pages) / max(total, 1)) * 100),
                    'status': status
                }, pf)
        except Exception:
            pass


if __name__ == "__main__":
    main()
