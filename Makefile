.PHONY: install install-dev lint format test clean

install:
	uv pip install -e ./sdk

install-dev:
	uv pip install -e ./sdk[dev]

lint:
	ruff check .

format:
	ruff format .

test:
	pytest tests/ --cov=sdk

clean:
	find . -type d -name "__pycache__" -exec rm -rf {} +
	rm -rf .pytest_cache .ruff_cache
