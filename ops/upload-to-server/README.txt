Files in this folder are NOT deployed by git (the server ignores images).
Upload them by hand with the hosting file manager, same relative paths, overwriting the old files:

  templates/ryba/images/logo.png   (137 KB -> 40 KB, 260x246, used for the app icon / mobile header)
  images/logo.jpg                  (137 KB -> 26 KB, 196x186, shown in the site header at 65 px; it is a PNG with a .jpg name, as before)

Both keep the transparent background and look the same.

----------------------------------------------------------------------
2026-10-23: files that are in git but are missing on the server (HTTP 404)
----------------------------------------------------------------------
The server does not pick up new image files and the new lightbox script from git.
Upload them by hand into the site root (the folder that contains index.php, usually public_html),
keeping the same relative paths:

  icons/vigling-pwa-splash-192.png          (app launch screen, requested on every page)
  icons/vigling-pwa-splash-512.png          (app launch screen, requested on every page)
  templates/ryba/js/vg-lightbox.js          (own lightbox that replaced fancyBox; the install-guide pictures open in it)

Quickest way: upload upload-missing-files-2026-10-23.zip into the site root with the file manager
and use "Extract" there. The folders icons/ and templates/ryba/js/ already exist, the three files are added.

Check afterwards (all three must open, not show the 404 page):
  https://vigling.ru/icons/vigling-pwa-splash-192.png
  https://vigling.ru/icons/vigling-pwa-splash-512.png
  https://vigling.ru/templates/ryba/js/vg-lightbox.js
Then open the "Установка приложения" page and tap a picture: it should open in a window over the page.
After the check you can delete the zip from the server.
