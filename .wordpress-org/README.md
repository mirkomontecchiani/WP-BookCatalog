# WordPress.org directory assets

The files in `assets/` are the images the WordPress.org plugin directory shows on the
public plugin page. **They are not part of the plugin**: they belong to the `assets/`
folder at the root of the plugin's Subversion repository, next to `trunk/`, `tags/` and
`branches/`, and they must never be copied into `trunk/`.

```
montecchiani-book-catalog/     <- SVN repository root
├── assets/                    <- the files in this folder go here
├── trunk/
├── tags/
└── branches/
```

| File | What it is |
| --- | --- |
| `icon-128x128.png` | Icon in the search results and in the "Add Plugins" screen |
| `icon-256x256.png` | Same icon for high density screens |
| `banner-772x250.png` | Banner at the top of the public plugin page |
| `banner-1544x500.png` | Same banner for high density screens |
| `screenshot-1.png`, ... | Screenshots, each one described in the `== Screenshots ==` section of `readme.txt`, in the same order |

The images are served through a CDN with an aggressive cache, so replacing one can take a
few hours to show up on the plugin page.

## Regenerating the icon and the banner

`build-assets.py` draws them from scratch, so the design lives in code rather than in a
binary file. It needs Pillow and the Poppins and Kalam fonts:

```bash
pip install Pillow
# Put Poppins-Regular.ttf, Poppins-SemiBold.ttf and Kalam-Regular.ttf in a folder,
# they are both available from fonts.google.com under the Open Font License.
MBCAT_FONT_DIR=/path/to/fonts python3 .wordpress-org/build-assets.py
```

Screenshots are not generated: they are captured from a running WordPress site.
