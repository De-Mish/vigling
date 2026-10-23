Files in this folder are NOT deployed by git (the server ignores images).
Upload them by hand with the hosting file manager, same relative paths, overwriting the old files:

  templates/ryba/images/logo.png   (137 KB -> 40 KB, 260x246, used for the app icon / mobile header)
  images/logo.jpg                  (137 KB -> 26 KB, 196x186, shown in the site header at 65 px; it is a PNG with a .jpg name, as before)

Both keep the transparent background and look the same.

----------------------------------------------------------------------
2026-10-23: files that are in git but are missing on the server (HTTP 404)
----------------------------------------------------------------------
The server does not pick up new image files from git. Two launch-screen images must be uploaded by hand
into the site root (the folder that contains index.php, manifest.json and configuration.php), keeping the same relative paths:

  icons/vigling-pwa-splash-192.png
  icons/vigling-pwa-splash-512.png

Both go into the existing folder "icons" (the one that already holds vigling-pwa-192.png, vigling-pwa-512.png and so on).
Easiest check of the folder: find vigling-pwa-192.png in the file manager and upload the two splash files right next to it.

Check afterwards (both must open as pictures, not the 404 page):
  https://vigling.ru/icons/vigling-pwa-splash-192.png
  https://vigling.ru/icons/vigling-pwa-splash-512.png

(The lightbox script is no longer a separate file: it was moved into templates/ryba/js/redesign.js, which deploys from git.)
