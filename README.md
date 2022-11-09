# Multibucket Migrate

Allow moving users between buckets in a multibucket setup.

⚠ This app has only been minimally tested, backups are strongly recommended ⚠

## Usage

Move all objects owned by a user to a different bucket.

```bash
occ multibucket_migrate:move_user <user_id> <target_bucket>
```

Note: this can take a long time if the user owns a lot of data

## Manual migration

This app can also be used to assist in a more manual migration

- Disable the user to migrate: `occ user:disable <user_id>`
- Get the current bucket for the user: `occ user:setting <user_id> homeobjectstore bucket`
- List all objects owned by the user: `occ multibucket_migrate:list <user_id>`
- Move all listed objects to the target bucket
- Save the new bucket for the user: `occ user:setting <user_id> homeobjectstore bucket <target_bucket>`
- Re-enable the user: `occ user:enable <user_id>`

Note that it's important that this app stays enabled during the migration as it includes logic to ensure
shares owned by disabled users are readonly, preventing accidental writes to objects owned by the user being migrated. 

## Listing all users using a bucket

You can get all users who are using a specific bucket by using

```bash
occ multibucket_migrate:by-bucket <bucket>
```

## Listing all object owned by a user

You can get a list of all object belonging to a users home storage by using

```bash
occ multibucket_migrate:list <user_id>
```
