.PHONY: test

test:
	docker run --rm \
		-v "$(PWD):/app" \
		-w /app \
		composer:latest \
		sh -c "composer install --no-interaction -q && vendor/bin/phpunit"
