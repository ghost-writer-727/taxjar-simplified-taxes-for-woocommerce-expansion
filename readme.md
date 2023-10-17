Taxjar Expansion plugin 1.5.0

• Add support to sync additional order statuses beyond the default 'completed' & 'refunded'.

• Gives a place for users to upload/remove certificate to our server: /uploads/tax-certificates/{user_id}/{certificate_file}. All certifcates are stored on the server until manually deleted so they can be audited via FTP if needed.

• Gives a place for users to set expiration date of certificate or to claim a non-expiring 501c3 status.

• If cert is uploaded & expiration date set & is before expiration date, they will be auto marked as Tax Exempt in WC

• Allows you to set a starting date to begin requiring certificates for exemption. This will essentially bypass TaxJar until your predetermined start date to begin applying taxes. Helps during transitioning to TaxJar.

• Zaps included in case we need to do more to sync with TaxJar manually.
• • Expiration date change
• • 501(c)3 status change
• • Certificate file change
• • Exempt Status change

• Is fully integrated with Directory Offloader: If certificates are offloaded, it will update the certificate file location meta (which will also trigger zap if set)