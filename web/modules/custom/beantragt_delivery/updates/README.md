# Historical delivery DDL

These JSON files freeze the storage definitions used by updates 10004 through
10009, 10013, 10014 and 10015. They are deliberately independent of current repository methods and
`hook_schema()`. When fields or indexes are added, existing snapshots are
preserved and a new numbered update is added with its own definition.

The snapshots record the storage schema supported by the delivery updates.
The attachment option belongs to update 10008 and is absent from the earlier
integration definitions. `HistoricalUpdateSchemaTest` pins the files and checks
that historical update functions cannot silently use current schema builders.
Update 10009 adds the durable transfer planning table.
Update 10013 adds the transfer trigger to assignments and the trigger source
to planning intents; update 10014 adds outcome and kind to external responses; update 10015 adds
the case system's outcome label.
