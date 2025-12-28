# WP Book Catalog

A WordPress plugin to display a book catalog with custom post type, shortcodes and hover effects.

## Author

Mirko Montecchiani

## Features

- **Custom Post Type**: Dedicated "Book" post type with custom fields
- **Book Fields**:
  - Title
  - Author
  - Description (WordPress standard editor)
  - Cover image (Featured image)
  - Publisher
  - ISBN
  - Shop link
- **Shortcode Support**: Display books anywhere with `[books]`
- **Responsive Grid**: 1-5 columns with automatic responsive adjustments
- **Hover Effect**: Beautiful overlay with book details on hover
- **AJAX Loading**: "Show All" button loads remaining books without page reload
- **Settings Page**: Configure default author and number of columns

## Installation

1. Download or clone this repository
2. Upload the `wp-book-catalog` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress

## Usage

### Shortcodes

**Display all books:**
```
[books]
```

**Display limited books with "Show All" button:**
```
[books hitem="3"]
```

**Available attributes:**
- `hitem` - Number of books to display (shows "Show All" button)
- `columns` - Override default columns (1-5)
- `orderby` - Order by: date, title, rand (default: date)
- `order` - Order direction: ASC, DESC (default: DESC)

**Example with all attributes:**
```
[books hitem="6" columns="3" orderby="title" order="ASC"]
```

### Settings

Go to **Book Catalog > Settings** in the WordPress admin to configure:

1. **Default Author**: If set, this author will be used for all books that don't have an author specified
2. **Number of Columns**: Choose 1-5 columns for the grid layout (responsive)

## License

GPL v2 or later
