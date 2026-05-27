"""CLI entrypoint for the sentence fixer agent."""
import os
import json
import sys
import logging
import argparse
import glob
from typing import List, Dict, Any

from .agent import SentenceFixerAgent
from .fixer import fix_text_block, llm_fix_text

def main(argv=None):
    # Reconfigure stdout and stderr to handle UTF-8 printing in Windows terminal
    try:
        sys.stdout.reconfigure(encoding='utf-8')
        sys.stderr.reconfigure(encoding='utf-8')
    except Exception:
        pass

    parser = argparse.ArgumentParser(prog="sentence-fixer-agent", description="Sentence Fixer Agent CLI")
    parser.add_argument("input", nargs="?", help="Path to input JSON context file or text file to fix")
    parser.add_argument("--input-dir", default=None, help="Directory containing page_NNNN.json files")
    parser.add_argument("--output-dir", default=None, help="Directory to write fixed page_NNNN.json files incrementally")
    parser.add_argument("-o", "--out", default=None, help="Output JSON/txt file path for refined context")
    parser.add_argument("--progress-file", default=None, help="File to write progress updates to")
    parser.add_argument("--cancel-file", default=None, help="Path to cancel flag file; if it exists, processing stops")
    parser.add_argument("--page-num", type=int, default=None, help="Process only a specific page number")
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
    agent = SentenceFixerAgent()

    # Cancel check: returns True if the cancel flag file exists
    def cancel_check():
        if args.cancel_file and os.path.isfile(args.cancel_file):
            return True
        return False

    was_cancelled = False

    def progress_callback(page_num, total_pages, title="Sentence Fixer", status=None, custom_percent=None):
        if args.progress_file:
            try:
                if status is None:
                    status = f"Analyzing and fixing sentences on page {page_num} of {total_pages}..."
                
                percent = custom_percent if custom_percent is not None else (int((page_num / total_pages) * 100) if total_pages > 0 else 0)
                
                with open(args.progress_file, 'w', encoding='utf-8') as pf:
                    json.dump({
                        'page': page_num,
                        'total': total_pages,
                        'percent': min(100, max(0, percent)),
                        'title': title,
                        'status': status
                    }, pf)
            except Exception:
                pass

    if not args.input and not args.input_dir:
        print("Error: Must provide either input file or --input-dir.", file=sys.stderr)
        sys.exit(1)

    # Gather pages to process
    pages_to_process = []
    
    if args.input_dir and os.path.isdir(args.input_dir):
        if args.page_num:
            # Single page mode
            page_path = os.path.join(args.input_dir, f"page_{args.page_num:04d}.json")
            if os.path.isfile(page_path):
                try:
                    with open(page_path, 'r', encoding='utf-8') as f:
                        pages_to_process.append(json.load(f))
                except Exception as e:
                    print(f"Failed to load page {args.page_num}: {e}", file=sys.stderr)
        else:
            # Batch mode
            page_files = sorted(glob.glob(os.path.join(args.input_dir, "page_*.json")))
            for pf in page_files:
                try:
                    with open(pf, 'r', encoding='utf-8') as f:
                        pages_to_process.append(json.load(f))
                except Exception as e:
                    print(f"Failed to load {pf}: {e}", file=sys.stderr)
    elif args.input and os.path.exists(args.input):
        # Legacy single input file
        try:
            with open(args.input, 'r', encoding='utf-8') as f:
                content = f.read().strip()
                if content.startswith('[') or content.startswith('{'):
                    data = json.loads(content)
                    if not isinstance(data, list):
                        data = [data]
                    if args.page_num:
                        for p in data:
                            if int(p.get('page_number', p.get('source_page', 1))) == args.page_num:
                                pages_to_process = [p]
                                break
                    else:
                        pages_to_process = data
                else:
                    # Treat as raw text
                    fixed_text = llm_fix_text(fix_text_block(content))
                    if args.out:
                        with open(args.out, 'w', encoding='utf-8') as fOut:
                            fOut.write(fixed_text)
                    else:
                        print(fixed_text)
                    sys.exit(0)
        except Exception as e:
            print(f"Failed to process input: {e}", file=sys.stderr)
            sys.exit(1)
    else:
        print(f"Error: Input '{args.input or args.input_dir}' not found.", file=sys.stderr)
        sys.exit(1)

    total_pages = len(pages_to_process)
    refined_pages = []
    
    for idx, page in enumerate(pages_to_process, start=1):
        if cancel_check():
            was_cancelled = True
            logging.info("Sentence fixing cancelled at page %d/%d", idx, total_pages)
            break
            
        page_num = page.get('page_number', page.get('source_page', idx))
        refined_page = dict(page)
        
        if 'blocks' in page:
            from .fixer import process_blocks
            blocks = page['blocks']
            
            def page_progress_cb(step_pct, msg):
                base_percent = ((idx - 1) / max(total_pages, 1)) * 100
                step_contribution = (step_pct / 100) * (100 / max(total_pages, 1))
                global_pct = int(base_percent + step_contribution)
                progress_callback(idx, total_pages, title="Sentence Fixer", status=f"Page {page_num}: {msg}", custom_percent=global_pct)
                
            refined_page['fixed_blocks'] = process_blocks(blocks, progress_cb=page_progress_cb)
        elif 'text' in page:
            status_str = f"Fixing text block on page {page_num} of {total_pages}..."
            progress_callback(idx, total_pages, title="Sentence Fixer", status=status_str)
            refined_page['fixed'] = llm_fix_text(fix_text_block(page.get('text', '')))
            
        refined_pages.append(refined_page)

        # Write per-page JSON incrementally if output-dir is set
        if args.output_dir:
            try:
                os.makedirs(args.output_dir, exist_ok=True)
                page_path = os.path.join(args.output_dir, f'page_{page_num:04d}.json')
                with open(page_path, 'w', encoding='utf-8') as pf:
                    json.dump(refined_page, pf, ensure_ascii=False, indent=2)
            except Exception as e:
                logging.warning(f"Failed to write fixed page {page_num} JSON: {e}")

    # Write output to the single requested JSON file
    if args.out:
        try:
            with open(args.out, 'w', encoding='utf-8') as f:
                json.dump(refined_pages, f, ensure_ascii=False, indent=2)
            print(f"Wrote context to {args.out}")
        except Exception as e:
            print(f"Failed to write context to {args.out}: {e}", file=sys.stderr)
    elif not args.output_dir:
        print(json.dumps(refined_pages, ensure_ascii=False, indent=2))

    # Write final status to progress file
    if args.progress_file:
        try:
            status = 'cancelled' if was_cancelled else 'completed'
            final_pct = int((len(refined_pages) / max(total_pages, 1)) * 100)
            if not was_cancelled:
                final_pct = 100
                status = "Successfully finalized sentence corrections!"
                
            with open(args.progress_file, 'w', encoding='utf-8') as pf:
                json.dump({
                    'page': len(refined_pages),
                    'total': total_pages,
                    'percent': final_pct,
                    'status': status
                }, pf)
        except Exception:
            pass

if __name__ == "__main__":
    main()
