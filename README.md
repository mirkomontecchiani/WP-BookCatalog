# Montecchiani Book Catalog

A WordPress plugin to display a book catalog with a custom post type, shortcodes, hover
effects and ISBN autofill from external book databases.

> The canonical, user-facing documentation is [`readme.txt`](readme.txt), which is also what
> the WordPress.org plugin directory renders. This file is the developer README.

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
- **Shortcode Support**: Display books anywhere with `[mbcat_books]` (or `[mbcat_books]`)
- **Responsive Grid**: 1-5 columns with automatic responsive adjustments
- **Hover Effect**: Overlay with book details on hover (tap-friendly on touch devices, keyboard accessible)
- **AJAX Loading**: "Show All" button loads remaining books without a page reload
- **SEO**: Automatic Schema.org `Book` structured data (JSON-LD) for rich results in search engines
- **Admin Columns**: Cover, author, year and ISBN columns in the book list, sortable by author and year
- **Settings Page**: Configure default author, columns, ISBN data source and uninstall cleanup
- **i18n Ready**: Fully translatable; French, Spanish, Italian and Japanese translations are maintained on translate.wordpress.org

## Requirements

- WordPress 5.8 or later
- PHP 7.2 or later

## Installation

1. Download or clone this repository
2. Copy the plugin files into a folder named `montecchiani-book-catalog` inside `/wp-content/plugins/`
   (the folder name must match the text domain)
3. Activate the plugin through the 'Plugins' menu in WordPress

## Usage

### ISBN Autofill

1. Create a new Book (**Book Catalog > Add New Book**)
2. Enter the ISBN (10 or 13 digits, with or without dashes) in the *ISBN* field
3. Click **Autofill from ISBN**: empty fields (author, publisher, year, pages, language), the title and the description are filled automatically
4. If a cover is found, click **Use as cover** to import it as the featured image

Data sources can be configured in **Book Catalog > Settings** (Google Books, Open Library, or
both merged). An optional Google Books API key can be set to raise the request quota. Lookups
are cached for 12 hours.

### Shortcodes

**Display all books:**
```
[mbcat_books]
```

`[mbcat_books]` is an identical, prefixed alias; use it if another plugin also registers
`[mbcat_books]`.

**Display limited books with a "Show All" button:**
```
[books hitem="3"]
```

**Available attributes:**
- `hitem` - Number of books to display (shows the "Show All" button when more exist)
- `columns` - Override default columns (1-5)
- `orderby` - Order by: date, title, year, author, rand, menu_order, modified (default: date)
- `order` - Order direction: ASC, DESC (default: DESC)
- `genre` - Filter by genre slug(s), comma separated

**Example with all attributes:**
```
[books hitem="6" columns="3" orderby="title" order="ASC" genre="fantasy,thriller"]
```

At most 500 books are rendered per request; raise it with the
`mbcat_max_books_per_request` filter.

### Settings

Go to **Book Catalog > Settings** in the WordPress admin to configure:

1. **Default Author**: shown as a placeholder in the book editor and used at render time for every book that has no author of its own
2. **Author Gender**: controls the Author/Authoress label on the frontend
3. **Number of Columns**: choose 1-5 columns for the grid layout (responsive)
4. **ISBN Data Source**: Google Books, Open Library, or both merged (recommended)
5. **Google Books API Key** (optional): raises the API request quota
6. **Delete Data on Uninstall**: permanently remove books, genres and settings when the plugin is deleted

## Filters

| Filter | Description |
| --- | --- |
| `mbcat_shortcode_query_args` | The `WP_Query` arguments used to list books |
| `mbcat_max_books_per_request` | Maximum books rendered in one request (default 500) |
| `mbcat_author_label` | The label printed before the author name, per book |
| `mbcat_allowed_cover_hosts` | Hosts a cover image may be downloaded from |
| `mbcat_lookup_data` | The normalized record returned by an ISBN lookup |

## Privacy

The ISBN autofill feature sends the ISBN you type — and, if you configured one, your Google
Books API key — from your server to the Google Books API (`googleapis.com`) and/or the Open
Library API (`openlibrary.org`). Each request carries a `User-Agent` header naming the plugin
and its version; your site URL is not sent. Importing a cover downloads the image file from
`books.google.com`, `books.googleusercontent.com` or `covers.openlibrary.org` and stores it
in your Media Library. Nothing is requested from the public frontend, and no personal data
about you or your visitors is transmitted. See the "External services" section of
[`readme.txt`](readme.txt) for the full disclosure and the providers' terms.

## Development

```bash
# Regenerate the translation template and merge it into every catalogue
wp i18n make-pot . languages/montecchiani-book-catalog.pot --slug=montecchiani-book-catalog
for loc in fr_FR es_ES it_IT ja; do
  msgmerge --update --backup=none languages/montecchiani-book-catalog-$loc.po languages/montecchiani-book-catalog.pot
  msgfmt -o languages/montecchiani-book-catalog-$loc.mo languages/montecchiani-book-catalog-$loc.po
done

# Coding standards
phpcs --standard=WordPress-Extra --extensions=php .

# Build a release archive with the folder name WordPress.org expects
git archive --format=zip --prefix=montecchiani-book-catalog/ -o montecchiani-book-catalog.zip HEAD
```

## License

GPLv2 or later. See [LICENSE](LICENSE).
