# diff-slack-outlook
Compare Slack channel and Outlook Distribution list populations for manual reconciliation.

This work in progress is good enough to release for others to leverage!
If you manage a Slack channel and an Outlook-365 distribution list of the same name, you probably want to keep the populations in sync.
This tool will help reduce the work to manually reconcile both populations, but caveat emptor:

- People do not necessarily have the same primary email account name (without the domain) and Slack handle
- Outlook distribution lists can contain suspended accounts
  - https://outlook.office.com/mail/options/general/distributionGroups
Nevertheless, this helped me manage the populations.

## TODO ##
Plenty of improvements for someday:

- Hack on Outlook APIs to bypass the copy and paste an expanded Outlook list from the client
- map mismatched account names, substitute one source for equivalency
- (detect? and) blackhole suspended Outlook accounts
