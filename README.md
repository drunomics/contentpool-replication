# Contentpool replication
Module providing the replication filter for the [Contentpool](https://github.com/drunomics/contentpool) distribution.

The replication filter plugin must be available on both the replication "client" and "server". 
While the plugin is configured at the client, the configuration is sent to the to the server during replication. Then
the server actually applies the filter.
