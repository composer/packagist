# Packagist Development Notes

## Project
This is the package repository hosting PHP packages. The target audience is PHP developers.
It is a Symfony app using modern up to date versions of everything (MySQL, Redis, project dependencies).

## File Locations

### Styling
- Main CSS: `css/app.scss` (compiled to `web/build/app.css`)
- Uses Bootstrap 3 imported from `node_modules/bootstrap/dist/css/bootstrap.min.css`

### Templates
- API documentation: `templates/api_doc/index.html.twig`
- Layout base: `templates/layout.html.twig`

### Controllers
- API endpoints: `src/Controller/ApiController.php`
- Package routes: `src/Controller/PackageController.php`
- Web routes: `src/Controller/WebController.php`
- Explore routes: `src/Controller/ExploreController.php`

## API Authentication
- `findUser()` method in ApiController handles auth with `ApiType::Safe` vs `ApiType::Unsafe`
- Bearer token format: `Authorization: Bearer username:apiToken`
- Two token types: main (`apiToken`) and safe (`safeApiToken`)
- Bearer token takes precedence over query parameters in implementation

## Build Process
- Run `npm build` to build frontend files
- SCSS compiled to `web/build/app.css`

## Testing
- Run `composer phpstan` for PHPStan and `composer test -- --filter <test suite>` to run tests
- Avoid using "composer" vendor name in test for packages/repos as the validation blocks it

## Database and Entity Management
- No Doctrine migrations system - provide raw SQL for database changes

## General guidelines

- Prefer modern PHP features:
  - Constructor property promotion with public properties instead of getters/setters
  - Use virtual properties with accessor functions for computed values (not readonly if using get{})
  - Use meaningful property names (e.g., `contents` instead of generic names like `data`)
