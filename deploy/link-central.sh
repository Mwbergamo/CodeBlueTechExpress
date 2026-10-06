#!/usr/bin/env bash
#
# deploy/link-central.sh
#
# Run ON THE SERVER (cPanel -> Terminal), once after the first deploy of this
# repo and again any time the central install moves. It points this site's
# server-side pieces at the CENTRAL SolutionsHub install (the one that serves
# portal.codebluetechnology.com), so both domains share ONE database, ONE set
# of ConnectWise / Alt Pay credentials, and ONE sign-in.
#
#   bash deploy/link-central.sh /home/<cpanel-user>/public_html/portal
#   bash deploy/link-central.sh /home/<cpanel-user>/public_html/portal --check
#
# What it links (all relative to the site root):
#   register/api    -> <central>/register/api
#   ratesheet/api   -> <central>/ratesheet/api
#   auth            -> <central>/auth
#   login.php       -> <central>/login.php
#   logout.php      -> <central>/logout.php
#
# PHP resolves __DIR__ through symlinks, so the linked code keeps reading the
# CENTRAL config files, SQLite databases and session folder -- nothing is
# copied, nothing can drift. Safe to re-run. It never deletes anything: if a
# real file or folder is already where a link should go, it stops and says so.

set -euo pipefail

if [ $# -lt 1 ]; then
  echo "usage: bash deploy/link-central.sh <path-to-central-install> [--check]" >&2
  exit 2
fi

SITE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CENTRAL="$(cd "$1" 2>/dev/null && pwd)" || { echo "Central path not found: $1" >&2; exit 1; }
CHECK_ONLY="no"; [ "${2:-}" = "--check" ] && CHECK_ONLY="yes"

if [ "$SITE" = "$CENTRAL" ]; then
  echo "This site and the central install are the same folder; nothing to link." >&2
  exit 1
fi

ITEMS=(register/api ratesheet/api auth login.php logout.php)

# Make sure the central side actually has everything first.
for item in "${ITEMS[@]}"; do
  if [ ! -e "$CENTRAL/$item" ]; then
    echo "Central install is missing: $CENTRAL/$item" >&2
    echo "Is $CENTRAL really the SolutionsHub folder?" >&2
    exit 1
  fi
done

status=0
for item in "${ITEMS[@]}"; do
  target="$CENTRAL/$item"
  dest="$SITE/$item"
  if [ -L "$dest" ]; then
    if [ "$(readlink "$dest")" = "$target" ]; then
      echo "ok       $item -> $target"
    elif [ "$CHECK_ONLY" = "yes" ]; then
      echo "WRONG    $item -> $(readlink "$dest") (expected $target)"; status=1
    else
      ln -sfn "$target" "$dest"; echo "relinked $item -> $target"
    fi
  elif [ -e "$dest" ]; then
    echo "BLOCKED  $dest exists and is a real file/folder, not a link. Move it aside and re-run." >&2
    status=1
  elif [ "$CHECK_ONLY" = "yes" ]; then
    echo "MISSING  $item"; status=1
  else
    mkdir -p "$(dirname "$dest")"
    ln -s "$target" "$dest"; echo "linked   $item -> $target"
  fi
done

exit $status
