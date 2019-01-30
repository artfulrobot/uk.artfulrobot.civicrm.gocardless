#!/usr/bin/env bash
#
# Package the files for a release.
#
# All this does is create zip and tgz files that include the vendor/ directory.

COMPOSER=$(which composer)
if [ -z "$COMPOSER" ]
then
  COMPOSER=$(which composer.phar)
fi
if [ -z "$COMPOSER" ]
then
  echo "X neither composer nor composer.phar found in path. Cannot continue.";
  exit 1;
fi
which tar >/dev/null || { echo "X tar not found in path. Cannot continue."; exit 1; }
which zip >/dev/null || { echo "X zip not found in path. Cannot continue."; exit 1; }
SCRIPT=$(readlink -f "$0")
SCRIPTPATH=$(dirname "$SCRIPT")
cd $SCRIPTPATH/.. || { echo "X hmmm. failed to cd $SCRIPTPATH/.."; exit 1; }
# We should now be in the project root.
CWD=$(pwd)
PROJECT_NAME=$(basename "$CWD")
if [ "$PROJECT_NAME" != 'uk.artfulrobot.civicrm.gocardless' ]
then
  echo "X Expected repo to be in dir called uk.artfulrobot.civicrm.gocardless"
  exit 1
fi
# Extract version
if which xml_grep >/dev/null 2>&1
then
  VERSION=`xml_grep --text_only version info.xml`
  echo "got version with xml_grep: $VERSION"
elif which sed >/dev/null 2>&1
then
  # try sed. This will work as long as version is on one line.
  VERSION=`sed -n '/<version/{s/^.*<version>\s*\(.*\)\s*<\/version>/\1/;p}' info.xml`
  echo "got version with sed: $VERSION"
else
  echo "install xml_grep from xml-twig-tools or at least sed."
  VERSION='unknown'
fi
ARCHIVE_NAME="uk.artfulrobot.civicrm.gocardless-$VERSION"
# Ensure vendor/ is set up.
$COMPOSER install

cd ../ || { echo "X hmm. failed to go up dir."; exit 1; }
[ -w ./ ] || { echo "X cannot write files in " `pwd`; exit 1;}

# Remove any old versions.
rm -f "$ARCHIVE_NAME".zip "$ARCHIVE_NAME".tgz

tar czf "$ARCHIVE_NAME".tgz --exclude='.git*' --exclude='bin' --exclude='tests' --exclude='cli' "$PROJECT_NAME"

# PR wanted: if you can get zip to behave the same way as tar, please replace this clugey hack!
mkdir temp && cd temp || { echo "X failed making temp dir to create zip file"; exit 1; }
tar xzf ../"$ARCHIVE_NAME".tgz || { echo "X failed unpacking tar archive $ARCHIVE_NAME.tgz"; exit 1; }
zip -r -q ../"$ARCHIVE_NAME".zip "$PROJECT_NAME"/ || { echo "X failed making $ARCHIVE_NAME.zip"; exit 1; }
cd ../
rm -rf temp || { echo "W: created archives OK, but failed to remove the temp dir used in making the zip file."; exit 1; }

echo "SUCCESS: "
ls "$ARCHIVE_NAME".*
