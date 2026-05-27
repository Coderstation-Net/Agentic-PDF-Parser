# Agentic Document Processing Pipeline (PDF Parser)

A comprehensive Web UI and CLI toolset for processing, extracting, and enhancing data from PDF documents using multiple agentic components.

## Overview

This project provides a complete pipeline to parse PDF layouts, fix sentences, generate fine-tuning Q&A pairs, and create embeddings. It includes a rich PHP-based Web UI for managing documents and tracking background processes, alongside underlying Python CLI agents located in the `agentic_model.document_manager_agent` package.

The core agents included are:
- **Document Parser**: Extracts layouts, text, and tables from PDFs (via `pdfplumber`).
- **Sentence Fixer**: Processes extracted text to fix broken sentences and improve readability.
- **Fine Tuning Agent**: Generates context-based Q&A pairs for fine-tuning LLMs.
- **Embeddings Agent**: Generates vector embeddings for the extracted or fixed text.

## Project Structure

- `index.php` / `api.php`: The main PHP Web UI and API endpoints for managing the document processing workflow.
- `config.php`: Configuration settings for the Web UI (security, performance, feature flags).
- `agentic_model/`: Contains the Python-based agents and their CLI entrypoints.
- `contexts/`: Stores the output data for each document, organized into subdirectories (`extracted`, `sentence_fixer`, `fine_tuning`, `embeddings`).
- `uploads/`: Directory for uploaded PDF documents.

## Installation

1. **Python Environment**:
   Create a Python virtual environment and install dependencies:
   ```powershell
   python -m venv .venv
   .\.venv\Scripts\Activate.ps1
   pip install -r requirements.txt
   ```

2. **PHP Environment**:
   Ensure you have a web server (like Laragon, XAMPP, or built-in PHP server) running with PHP 7.4+ to serve the Web UI. Point your document root to this project directory.

## Web UI Usage

1. Open `index.php` in your web browser.
2. Upload a PDF or select an existing one.
3. The UI allows you to trigger and monitor background processing for:
   - PDF Parsing
   - Sentence Fixing
   - Q&A Fine-Tuning Generation
   - Embeddings Generation
4. You can view the progress of each task and manage the generated context files directly from the browser.

## CLI Usage

You can also invoke the agents directly via the command line:

**Document Parser:**
```powershell
# parse and print extracted context JSON to stdout
python -m agentic_model.document_manager_agent.document_parser_agent.cli path\to\file.pdf

# write extracted context JSON to file
python -m agentic_model.document_manager_agent.document_parser_agent.cli path\to\file.pdf -o contexts/output.json
```
*(Options: `--summary`, `--no-summary`, `--max-tokens`)*

**Sentence Fixer / Fine Tuning / Embeddings:**
These agents follow a similar CLI pattern. For example, to run the sentence fixer on a specific page:
```powershell
python -m agentic_model.document_manager_agent.sentence_fixer_agent.cli --input-dir "contexts/Document/extracted" --output-dir "contexts/Document/sentence_fixer" --page-num 1
```

## Testing

Run the parser CLI locally on a sample PDF (a test PDF is provided in `uploads/`):

```powershell
python -m agentic_model.document_manager_agent.document_parser_agent.cli uploads/Policies_on_Faculty_Workload.pdf --no-summary -o contexts/test.json
```

## Notes

- The parser heavily relies on `pdfplumber` and `Pillow`. Keep `requirements.txt` in sync.
- Make sure your `config.php` points to the correct Python executable if you encounter any path issues. The `get_python_executable()` function automatically looks for the `.venv` directory.