"""CLI entrypoint for the embeddings agent."""
import os
import json
import sys
import logging
import argparse
from typing import List, Dict, Any

from .agent import EmbeddingsAgent

def main(argv=None):
    # Reconfigure stdout and stderr to handle UTF-8 printing in Windows terminal
    try:
        sys.stdout.reconfigure(encoding='utf-8')
        sys.stderr.reconfigure(encoding='utf-8')
    except Exception:
        pass

    parser = argparse.ArgumentParser(prog="embeddings-agent", description="Embeddings Agent CLI")
    parser.add_argument("input", help="Path to input fine-tuned JSON Q&A file")
    parser.add_argument("-o", "--out", default=None, help="Output JSON path for embedding vectors")
    parser.add_argument("--output-dir", default=None, help="Directory to write page_NNNN.json files incrementally")
    parser.add_argument("--progress-file", default=None, help="File to write progress updates to")
    parser.add_argument("--cancel-file", default=None, help="Path to cancel flag file; if it exists, processing stops")
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
    agent = EmbeddingsAgent()

    # Cancel check callback
    def cancel_check():
        if args.cancel_file and os.path.isfile(args.cancel_file):
            return True
        return False

    was_cancelled = False

    def progress_callback(item_num, total_items, percent, status=None):
        if args.progress_file:
            try:
                if status is None:
                    status = f"Generating embedding for page {item_num} of {total_items}..."
                
                with open(args.progress_file, 'w', encoding='utf-8') as pf:
                    json.dump({
                        'page': item_num,
                        'total': total_items,
                        'percent': min(100, max(0, percent)),
                        'title': "Embeddings Agent",
                        'status': status
                    }, pf)
            except Exception:
                pass

    if not os.path.exists(args.input):
        print(f"Error: Input file '{args.input}' not found.", file=sys.stderr)
        sys.exit(1)

    try:
        with open(args.input, 'r', encoding='utf-8') as f:
            qa_items = json.load(f)
        
        if not isinstance(qa_items, list):
            qa_items = [qa_items]

        # Determine total unique pages in input
        pages_set = set()
        is_page_objects = False
        if qa_items:
            first = qa_items[0]
            if 'fixed_blocks' in first or 'qa_pairs' in first or ('question' not in first and 'answer' not in first):
                is_page_objects = True
        
        if is_page_objects:
            for page in qa_items:
                pages_set.add(page.get('page_number') or 1)
        else:
            for item in qa_items:
                pages_set.add(item.get('page_number') or item.get('Page Number') or item.get('page') or 1)
        total_pages = len(pages_set) if pages_set else 1

        # Resolve fixed.json path if input is fine_tuned.json and sibling sentence_fixer exists
        fixed_data = None
        input_dir = os.path.dirname(os.path.abspath(args.input))
        if os.path.basename(args.input) == 'fine_tuned.json':
            sibling_fixed = os.path.abspath(os.path.join(input_dir, '..', 'sentence_fixer', 'fixed.json'))
            if os.path.isfile(sibling_fixed):
                try:
                    with open(sibling_fixed, 'r', encoding='utf-8') as ff:
                        fixed_data = json.load(ff)
                    logging.info(f"Loaded fixed sentence context from sibling path: {sibling_fixed}")
                except Exception as e:
                    logging.warning(f"Could not load sibling fixed.json: {e}")

        progress_callback(0, total_pages, 0, "Initializing embedding generation...")

        def cancel_check_inner():
            nonlocal was_cancelled
            if cancel_check():
                was_cancelled = True
                return True
            return False
        
        embedded_results = agent.generate_embeddings(
            qa_items,
            fixed_data=fixed_data,
            progress_cb=progress_callback,
            cancel_check=cancel_check_inner,
            output_dir=args.output_dir
        )

        # Signal completed or cancelled status
        if was_cancelled:
            progress_callback(len(embedded_results), total_pages, int((len(embedded_results)/max(total_pages, 1))*100), "Embeddings generation cancelled!")
            if args.progress_file:
                try:
                    with open(args.progress_file, 'w', encoding='utf-8') as pf:
                        json.dump({
                            'page': len(embedded_results),
                            'total': total_pages,
                            'percent': int((len(embedded_results)/max(total_pages, 1))*100),
                            'title': "Embeddings Agent",
                            'status': "cancelled"
                        }, pf)
                except Exception:
                    pass
        else:
            progress_callback(total_pages, total_pages, 100, "Successfully generated all vector embeddings!")

        # Write output (even if partial/cancelled, we can write what we generated, or only write if successful. Standard is to write what we got or write if completed)
        if args.out:
            out_dir = os.path.dirname(args.out)
            if out_dir and not os.path.exists(out_dir):
                os.makedirs(out_dir, exist_ok=True)
            with open(args.out, 'w', encoding='utf-8') as f:
                json.dump(embedded_results, f, ensure_ascii=False, indent=2)
            print(f"Successfully generated and wrote vector embeddings to {args.out}")
        else:
            print(json.dumps(embedded_results, ensure_ascii=False, indent=2))
    
    except Exception as e:
        print(f"Failed to process JSON input: {e}", file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    main()
