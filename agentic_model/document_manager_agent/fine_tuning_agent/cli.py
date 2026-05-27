"""CLI entrypoint for the fine tuning agent."""
import os
import json
import sys
import logging
import argparse
import glob
from typing import List, Dict, Any

from .agent import FineTuningAgent

def main(argv=None):
    # Reconfigure stdout and stderr to handle UTF-8 printing in Windows terminal
    try:
        sys.stdout.reconfigure(encoding='utf-8')
        sys.stderr.reconfigure(encoding='utf-8')
    except Exception:
        pass

    parser = argparse.ArgumentParser(prog="fine-tuning-agent", description="Fine Tuning Agent CLI")
    parser.add_argument("input", nargs="?", help="Path to input JSON context file")
    parser.add_argument("--input-dir", default=None, help="Directory containing page_NNNN.json files")
    parser.add_argument("--output-dir", default=None, help="Directory to write fine-tuned page_NNNN.json files incrementally")
    parser.add_argument("-o", "--out", default=None, help="Output JSON path for Q&A pairs")
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
    agent = FineTuningAgent()

    # Cancel check: returns True if the cancel flag file exists
    def cancel_check():
        if args.cancel_file and os.path.isfile(args.cancel_file):
            return True
        return False

    was_cancelled = False

    def progress_callback(page_num, total_pages, title="Fine Tuning", status=None, custom_percent=None):
        if args.progress_file:
            try:
                if status is None:
                    status = f"Generating Q&A pairs on page {page_num} of {total_pages}..."
                
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
        except Exception as e:
            print(f"Failed to process input: {e}", file=sys.stderr)
            sys.exit(1)
    else:
        print(f"Error: Input '{args.input or args.input_dir}' not found.", file=sys.stderr)
        sys.exit(1)

    total_pages = len(pages_to_process)
    all_qa_pairs = []
    
    for idx, page in enumerate(pages_to_process, start=1):
        if cancel_check():
            was_cancelled = True
            logging.info("Fine tuning cancelled at page %d/%d", idx, total_pages)
            break
            
        page_num = page.get('page_number', page.get('source_page', idx))
        context = agent.extract_page_text(page)
        
        def page_progress_cb(step_pct, msg):
            base_percent = ((idx - 1) / max(total_pages, 1)) * 100
            step_contribution = (step_pct / 100) * (100 / max(total_pages, 1))
            global_pct = int(base_percent + step_contribution)
            progress_callback(idx, total_pages, title="Fine Tuning Agent", status=f"Page {page_num}: {msg}", custom_percent=global_pct)
            
        progress_callback(idx, total_pages, title="Fine Tuning Agent", status=f"Generating Q&A pairs for page {page_num}...", custom_percent=int(((idx - 1) / max(total_pages, 1)) * 100))
        
        try:
            qa_pairs = agent.generate_qa_pairs(context, page_num, progress_cb=page_progress_cb, cancel_check=cancel_check)
            all_qa_pairs.extend(qa_pairs)
        except Exception as e:
            if "cancel" in str(e).lower() or cancel_check():
                was_cancelled = True
                logging.info(f"Fine tuning cancelled during generation on page {page_num}")
                break
            else:
                logging.error(f"Error generating Q&A pairs for page {page_num}: {e}")
                qa_pairs = []

        # Write per-page JSON incrementally to output-dir (saving fine_tuning/page_000N.json)
        if args.output_dir and not was_cancelled:
            try:
                os.makedirs(args.output_dir, exist_ok=True)
                page_path = os.path.join(args.output_dir, f'page_{page_num:04d}.json')
                
                # Load existing page structure if any, or initialize
                page_data = dict(page)
                page_data['qa_pairs'] = qa_pairs
                
                with open(page_path, 'w', encoding='utf-8') as pf:
                    json.dump(page_data, pf, ensure_ascii=False, indent=2)

                # Immediately write progress update of completed page
                progress_callback(
                    idx,
                    total_pages,
                    title="Fine Tuning Agent",
                    status=f"Finished page {page_num} of {total_pages}...",
                    custom_percent=int((idx / max(total_pages, 1)) * 100)
                )
            except Exception as e:
                logging.warning(f"Failed to write fine-tuned page {page_num} JSON: {e}")

    # Write output to the single requested JSON file
    if args.out:
        try:
            out_dir = os.path.dirname(args.out)
            if out_dir and not os.path.exists(out_dir):
                os.makedirs(out_dir, exist_ok=True)
            with open(args.out, 'w', encoding='utf-8') as f:
                json.dump(all_qa_pairs, f, ensure_ascii=False, indent=2)
            print(f"Successfully generated and wrote Q&A dataset to {args.out}")
        except Exception as e:
            print(f"Failed to write dataset to {args.out}: {e}", file=sys.stderr)
    elif not args.output_dir:
        print(json.dumps(all_qa_pairs, ensure_ascii=False, indent=2))

    # Write final status to progress file
    if args.progress_file:
        try:
            status = 'cancelled' if was_cancelled else 'completed'
            if not was_cancelled:
                final_pct = 100
                status = "Successfully finalized fine-tuning dataset!"
            else:
                final_pct = int(((idx - 1) / max(total_pages, 1)) * 100)
                
            with open(args.progress_file, 'w', encoding='utf-8') as pf:
                json.dump({
                    'page': len(pages_to_process) if not was_cancelled else idx - 1,
                    'total': total_pages,
                    'percent': final_pct,
                    'status': status
                }, pf)
        except Exception:
            pass

if __name__ == "__main__":
    main()
