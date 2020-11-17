# Alternative install instructions

As well as installing in-app, you can install from the releases page, or
developers might like to install from `git clone`.

The packaged version of this extension include the GoCardlessPro PHP libraries
and exclude some dev-only bits including the `bin`, `cli` and `tests`
directories.

## Installing from release archive

The [releases
page](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/releases)
lists all the releases; you normally want the latest one.

**Be sure to download the .tgz or .zip file with the full name of the
extension in it, not the source code**

Unzip that in your extensions directory, then install.

## Installing from git clone

This extension requires the GoCardlessPro PHP library. Here's how to install
from the \*nix command line. You need
[composer](https://getcomposer.org/download/).

    $ cd /path/to/your/extensions/dir
    $ git clone https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless.git
    $ cd uk.artfulrobot.civicrm.gocardless
    $ composer install
    $ cv en gocardless # assuming you have cv installed.

That should then bring in the GoCardlessPro dependency and you should be good to
go.

!!! note "Worth a mention"
    Side note: pre 1.9.3, this extension bundled a version of the Guzzle
    library. Since this is already included in CiviCRM (and has been for
    a while) it is now no longer included.


