# Lemonade CMS News

\`johnnyxlemonade/admin-cms-news\` je volitelný CMS modul s kódem \`cms.news\`.
Po instalaci a povolení přidává správu článků na \`/admin/news\`.

Článek má lokalizovaný title, URL adresu, summary, content a SEO metadata.
Publication a media jsou vlastnosti article rootu. Galerie a přílohy používají
sdílené file collections Admin Platformy; balíček nezavádí vlastní upload nebo
úložiště. Canonical URL rezervuje přes sdílený kontrakt \`cms_route\`.

Balíček nevlastní veřejné frontend HTML ani rendering článků.

## Instalace

Balíček vyžaduje PHP \`>=8.3 <8.6\`, \`johnnyxlemonade/framework\` a
\`johnnyxlemonade/admin-platform\`.

    composer require johnnyxlemonade/admin-cms-news:dev-main

Modul objevuje Composer metadata; následně jej nainstalujte a povolte standardním
module lifecycle host aplikace.

## Vývoj a QA

Kontroly z rootu balíčku:

    composer cs:check
    composer stan
    composer test
    composer qa
