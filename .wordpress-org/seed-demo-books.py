#!/usr/bin/env python3
"""
Fills a local WordPress with real books, so the plugin can be screenshotted with
a catalog that looks like a real one instead of with "Test book 1".

Every detail of every book, the author, the publisher, the year, the page count,
the ISBN and the cover, comes from the Open Library API. Nothing is invented
here: the only hand written part is the list of titles and the genre each one is
filed under.

Run it from the WordPress root, with WP-CLI on the PATH and the site installed:

    cd ~/wp-mbcat
    python3 -I wp-content/plugins/montecchiani-book-catalog/.wordpress-org/seed-demo-books.py

Running it twice is harmless: books that are already there are skipped.

Author: Mirko Montecchiani (mirkomontecchiani.com)
"""

import json
import os
import subprocess
import sys
import time
import urllib.parse
import urllib.request

# Title, author and the genre the book is filed under. The genre is the only
# editorial choice in this file, everything else is looked up.
BOOKS = [
    ('Pride and Prejudice', 'Jane Austen', 'Classics'),
    ('Jane Eyre', 'Charlotte Bronte', 'Classics'),
    ('Wuthering Heights', 'Emily Bronte', 'Classics'),
    ('Great Expectations', 'Charles Dickens', 'Classics'),
    ('Frankenstein', 'Mary Shelley', 'Gothic'),
    ('Dracula', 'Bram Stoker', 'Gothic'),
    ('The Picture of Dorian Gray', 'Oscar Wilde', 'Gothic'),
    ('Moby Dick', 'Herman Melville', 'Adventure'),
    ('Treasure Island', 'Robert Louis Stevenson', 'Adventure'),
    ('The Time Machine', 'H. G. Wells', 'Science Fiction'),
    ('The War of the Worlds', 'H. G. Wells', 'Science Fiction'),
    ('The Adventures of Sherlock Holmes', 'Arthur Conan Doyle', 'Mystery'),
]

# The shortcode the demo page gets: four columns, eight books shown straight
# away, so the "Show All Books" button has something left to load.
PAGE_SHORTCODE = '[mbcat_books columns="4" hitem="8"]'

API = 'https://openlibrary.org'
# Open Library asks for a user agent that says who is calling and why.
USER_AGENT = 'montecchiani-book-catalog-demo-seed/1.0 (local screenshot fixtures)'

SEARCH_FIELDS = (
    'key,title,author_name,first_publish_year,number_of_pages_median,'
    'isbn,cover_i,publisher'
)


def wp(*args, check=True):
    """Runs a WP-CLI command and returns its trimmed output."""
    result = subprocess.run(
        ['wp', *args], capture_output=True, text=True, check=False
    )
    if check and result.returncode != 0:
        raise RuntimeError((result.stderr or result.stdout).strip())
    return result.stdout.strip()


def get_json(url):
    """Fetches a URL and parses the JSON it returns."""
    request = urllib.request.Request(url, headers={'User-Agent': USER_AGENT})
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.load(response)


def pick_isbn(isbns):
    """Prefers a 13 digit ISBN, which is what a modern edition carries."""
    if not isbns:
        return ''
    for isbn in isbns:
        if len(isbn) == 13 and isbn.startswith(('978', '979')):
            return isbn
    return isbns[0]


def shorten(text, limit=220):
    """Cuts a description at a word boundary, so the overlay stays readable."""
    text = ' '.join(text.split())
    if len(text) <= limit:
        return text
    return text[:limit].rsplit(' ', 1)[0] + '...'


def describe(work_key):
    """Reads the description of a work, which the search endpoint does not carry."""
    try:
        work = get_json('%s%s.json' % (API, work_key))
    except Exception:
        return ''
    description = work.get('description')
    if isinstance(description, dict):
        description = description.get('value', '')
    return shorten(description or '')


def look_up(title, author):
    """Looks a book up and returns the fields the plugin stores."""
    query = urllib.parse.urlencode(
        {'title': title, 'author': author, 'limit': 1, 'fields': SEARCH_FIELDS}
    )
    results = get_json('%s/search.json?%s' % (API, query)).get('docs') or []
    if not results:
        return None

    doc = results[0]
    publishers = doc.get('publisher') or []
    cover_id = doc.get('cover_i')

    return {
        'title': title,
        'author': (doc.get('author_name') or [author])[0],
        'publisher': publishers[0] if publishers else '',
        'year': str(doc.get('first_publish_year') or ''),
        'pages': str(doc.get('number_of_pages_median') or ''),
        'isbn': pick_isbn(doc.get('isbn')),
        'language': 'English',
        # The Open Library page of the work: a real link, so the shop button in
        # the overlay has somewhere to go. Replace it with a real shop if you
        # would rather show that.
        'shop_link': '%s%s' % (API, doc['key']) if doc.get('key') else '',
        'cover': (
            'https://covers.openlibrary.org/b/id/%s-L.jpg' % cover_id
            if cover_id
            else ''
        ),
        'excerpt': describe(doc['key']) if doc.get('key') else '',
    }


def ensure_genre(name):
    """Creates the genre if it is not there yet and returns its slug."""
    slug = name.lower().replace(' ', '-')
    wp('term', 'create', 'mbcat_genre', name, '--slug=' + slug, check=False)
    return slug


def existing_titles():
    """Titles already in the catalog, so a second run does not duplicate them."""
    raw = wp(
        'post', 'list', '--post_type=mbcat_book', '--post_status=any',
        '--field=post_title', '--format=json'
    )
    return set(json.loads(raw or '[]'))


def update_demo_page():
    """Points the demo page at a shortcode that shows the expand button."""
    page_id = wp(
        'post', 'list', '--post_type=page', '--name=library',
        '--field=ID', '--format=ids'
    )
    if page_id:
        wp('post', 'update', page_id.split()[0], '--post_content=' + PAGE_SHORTCODE)
        return True
    return False


def main():
    if not os.path.isfile('wp-config.php'):
        sys.exit('Run this from the WordPress root, the folder that holds wp-config.php.')

    done = existing_titles()
    created = 0

    for title, author, genre in BOOKS:
        if title in done:
            print('%-38s already there, skipped' % title)
            continue

        try:
            book = look_up(title, author)
        except Exception as error:
            print('%-38s lookup failed: %s' % (title, error))
            continue

        if not book:
            print('%-38s not found on Open Library' % title)
            continue

        post_id = wp(
            'post', 'create',
            '--post_type=mbcat_book',
            '--post_status=publish',
            '--post_title=' + book['title'],
            '--post_excerpt=' + book['excerpt'],
            '--porcelain',
        )

        for field in ('author', 'publisher', 'year', 'pages', 'language', 'isbn', 'shop_link'):
            if book[field]:
                wp('post', 'meta', 'set', post_id, 'mbcat_' + field, book[field])

        wp('post', 'term', 'set', post_id, 'mbcat_genre', ensure_genre(genre))

        if book['cover']:
            wp(
                'media', 'import', book['cover'],
                '--post_id=' + post_id, '--featured_image', '--porcelain',
                check=False,
            )

        created += 1
        print('%-38s created (%s, %s)' % (title, book['author'], book['year'] or 'year unknown'))
        # Open Library is a free service: one request a second is polite.
        time.sleep(1)

    print('\n%d books created, %d already there.' % (created, len(BOOKS) - created))
    if update_demo_page():
        print('The "Library" page now shows %s' % PAGE_SHORTCODE)


if __name__ == '__main__':
    main()
