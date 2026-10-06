#!/usr/bin/env bash
#
# Runs browser tests against a throwaway module database instead of the development one, so the
# questions, examinations and audit rows they leave behind never reach it.
#
#   tests/browser/on_test_database.sh tests/browser/exam_paper.mjs
#
# For the few minutes it takes, the module (https://kmu-assess.test) is pointed at the throwaway
# database by changing DB_DATABASE in .env; the original .env is put back when the run ends, however
# it ends. kmu-cms is not touched except by what each test does and cleans up itself. Local
# development only.
set -euo pipefail

cd "$(dirname "$0")/../.."
DB="${BROWSER_DB:-kmu_assess_browser}"

[ -f .env.browser-backup ] && { echo ".env.browser-backup exists: a previous run did not finish. Restore it first." >&2; exit 2; }
cp .env .env.browser-backup
restore() {
    mv -f .env.browser-backup .env
    php artisan config:clear >/dev/null 2>&1 || true
}
trap restore EXIT

case "$DB" in
    kmu_assess|kmu-cms|kmu_assess_testing|mysql|sys) echo "Refusing to rebuild $DB." >&2; exit 2 ;;
esac
# Each run starts from an empty throwaway database.
mysql -uroot -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
sed -i.tmp "s/^DB_DATABASE=.*/DB_DATABASE=$DB/" .env && rm -f .env.tmp

php artisan migrate --force >/dev/null
# The read-only CMS user may read the views of the throwaway database too (grants only: the ones
# on the development database stay).
php artisan cms:reader-sql | grep '^GRANT' | mysql -uroot

status=0
for test in "$@"; do
    echo "== $test (database $DB)"
    MYSQL_ASSESS="mysql -uroot $DB" node "$test" || status=1
done
exit $status
