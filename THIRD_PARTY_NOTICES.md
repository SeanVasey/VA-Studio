# Third-party notices

VASEY.AUDIO project-specific code and identity assets are proprietary. That statement does not replace the licenses of incorporated open-source code, dependencies, or fonts.

## Application foundation

The initial application structure derives from the official [Laravel application skeleton](https://github.com/laravel/laravel), which identifies its framework as MIT-licensed. Laravel and all Composer packages retain the notices and license files installed with them. Exact package versions and source revisions are in `composer.lock`; the package licenses are listed there and in each package's installed distribution.

React, Inertia, Vite and other JavaScript dependencies retain their upstream licenses. Exact resolved versions and integrity values are in `package-lock.json`. Bundling does not transfer ownership of third-party components to VASEY.AUDIO.

## Fonts

The storefront self-hosts the chosen Fontsource fonts. They retain their individual Open Font License notices. See `docs/brand/` for the font provenance and included notices; preserve those notices when redistributing the font binaries or generated site assets.

## Existing brand imagery

The six existing published brand image files are user-owned assets acquired from the current VASEY.AUDIO theme. `docs/brand/asset-manifest.json` records their source, byte dimensions and SHA-256. The geometric product identity has not been recreated. Do not infer a third-party trademark or equipment endorsement from atmospheric studio imagery.

## Distribution practice

Do not vendor dependency directories into Git. Distribute dependencies with their license notices intact through their supported package installers. If a later deployment process strips comments or emits bundled third-party notices, review its output and retain the required notices. No open-source license is granted for Sean's music, masters, stems, artworks, trademarks or license templates by installing this project.
