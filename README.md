# WP Book Catalog

A WordPress plugin to display a book catalog with custom post type, shortcodes, hover effects and ISBN autofill from external book databases.

## Author

Mirko Montecchiani

## Features

- **Custom Post Type**: Dedicated "Book" post type with custom fields
- **ISBN Autofill**: Enter an ISBN and fetch title, author, publisher, year, pages, description and cover automatically from **Google Books** and **Open Library** (results are merged and cached)
- **Cover Import**: One click imports the fetched cover into the Media Library and sets it as the featured image
- **Book Fields**:
  - Title
  - Author
  - Description (WordPress standard editor + excerpt)
  - Cover image (Featured image)
  - Publisher
  - Publication year
  - Number of pages
  - Language
  - ISBN
  - Shop link
- **Genres Taxonomy**: Organize books by genre and filter the shortcode by genre
- **Shortcode Support**: Display books anywhere with `[books]`
- **Responsive Grid**: 1-5 columns with automatic responsive adjustments
- **Hover Effect**: Beautiful overlay with book details on hover (tap-friendly on touch devices, keyboard accessible)
- **AJAX Loading**: "Show All" button loads remaining books without page reload
- **SEO**: Automatic Schema.org `Book` structured data (JSON-LD) for rich results in search engines
- **Admin Columns**: Cover, author, year and ISBN columns in the book list, sortable by author and year
- **Settings Page**: Configure default author, columns, ISBN data source and uninstall cleanup
- **i18n Ready**: Fully translatable, Italian translation included

## Installation

1. Download or clone this repository
2. Upload the `wp-book-catalog` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress

## Usage

### ISBN Autofill

1. Create a new Book (**Book Catalog > Add New Book**)
2. Enter the ISBN (10 or 13 digits, with or without dashes) in the *ISBN* field
3. Click **Autofill from ISBN**: empty fields (author, publisher, year, pages, language), the title and the description are filled automatically
4. If a cover is found, click **Use as cover** to import it as the featured image

Data sources can be configured in **Book Catalog > Settings** (Google Books, Open Library, or both merged). An optional Google Books API key can be set to raise the request quota. Lookups are cached for 12 hours.

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
- `hitem` - Number of books to display (shows "Show All" button when more exist)
- `columns` - Override default columns (1-5)
- `orderby` - Order by: date, title, year, author, rand, menu_order, modified (default: date)
- `order` - Order direction: ASC, DESC (default: DESC)
- `genre` - Filter by genre slug(s), comma separated

**Example with all attributes:**
```
[books hitem="6" columns="3" orderby="title" order="ASC" genre="fantasy,thriller"]
```

### Settings

Go to **Book Catalog > Settings** in the WordPress admin to configure:

1. **Default Author**: If set, this author will be used for all books that don't have an author specified
2. **Author Gender**: Controls the Author/Authoress label on the frontend
3. **Number of Columns**: Choose 1-5 columns for the grid layout (responsive)
4. **ISBN Data Source**: Google Books, Open Library, or both merged (recommended)
5. **Google Books API Key** (optional): raises the API request quota
6. **Delete Data on Uninstall**: permanently remove books, genres and settings when the plugin is deleted

## Privacy

The ISBN autofill feature sends the ISBN you type to the Google Books API (`googleapis.com`) and/or the Open Library API (`openlibrary.org`) from your server. No other data is transmitted. Nothing is sent on the public frontend.

## License

GPL v2 or later
