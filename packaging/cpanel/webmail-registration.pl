#!/usr/local/cpanel/3rdparty/bin/perl

use strict;
use warnings;
use lib '/usr/local/cpanel';
use Cpanel::DataStore;

my $app = {
    url         => '/3rdparty/axerok-mail/public/cpanel.php',
    displayname => 'AxerOK Mail',
    icon        => '/3rdparty/axerok-mail/public/icon.png',
};

Cpanel::DataStore::store_ref('/var/cpanel/webmail/webmail_axerok_mail.yaml', $app)
    || die "No se pudo registrar AxerOK Mail en Webmail\n";
