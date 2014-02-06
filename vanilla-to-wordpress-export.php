#!/usr/bin/php -q
<?php

// Define the path to the configuration file.
define( 'CFG_FILE_PATH', 'vanilla-to-wordpress-export.cfg' );


/*
 * Function which increments the comment count for the specified post ID.
 *
 * @param string $post_id - a string representation of the post ID
 * @param reference $comment_count - reference to associative array of post IDs and comment count per ID
 * @return none
 *
 */
function increment_comment_count( $post_id, &$comment_count ) {
    if ( isset( $comment_count[ $post_id ] ) ) {
        $comment_count[ $post_id ] ++;
    } else {
        $comment_count[ $post_id ] = 1;
    }
}


// Process the configuration file.
$config_options = parse_ini_file( CFG_FILE_PATH );


if ( false !== $config_options ) {
    // Array containing the comment count for each post ID.
    $comment_count = array();

    // Connect to Vanilla db for the select part.
    $db_vanilla = @mysqli_connect( $config_options['vanilla_host'], $config_options['vanilla_user'], $config_options['vanilla_pass'], $config_options['vanilla_database'] );

    if ( ! mysqli_connect_errno( $db_vanilla ) ) {
        $select_query = 'SELECT c.Body AS content, c.DateInserted AS date, c.InsertIPAddress AS ip_address, u.Name AS name, u.Email AS email, d.ForeignID AS post_id FROM ' . $config_options['vanilla_comments_table'] . ' c LEFT JOIN ' . $config_options['vanilla_users_table'] . ' u ON c.InsertUserID = u.UserID LEFT JOIN ' . $config_options['vanilla_discussions_table'] . ' d ON c.DiscussionID = d.DiscussionID WHERE 1 ORDER BY c.DateInserted ASC';
        $res_select = mysqli_query( $db_vanilla, $select_query );

        if ( mysqli_error( $db_vanilla ) === '' ) {
            // Connect to the Wordpress db for getting the db locale.
            $db_wordpress = @mysqli_connect( $config_options['wordpress_host'], $config_options['wordpress_user'], $config_options['wordpress_pass'], $config_options['wordpress_database'] );
            
            if ( ! mysqli_connect_errno( $db_wordpress ) ) {
                // Initialize the insert query string
                $output_query_string = 'INSERT INTO `' . $config_options['wordpress_tables_prefix'] . 'comments`(comment_post_ID, comment_author, comment_author_email, comment_author_IP, comment_date, comment_date_gmt, comment_content) VALUES ';
                // Perform a first fetch of the insert data
                $row = mysqli_fetch_assoc( $res_select );
                // The post ID might be badly formatted. Just extract the integer.
                $int_post_id = intval( $row['post_id'] );
                // Concatenate to the query string the first row to be inserted (no comma at the beginning of the string!).
                $output_query_string .= '(' . $int_post_id . ", '" . mysqli_real_escape_string( $db_wordpress, $row['name'] ) . "', '" . mysqli_real_escape_string( $db_wordpress, $row['email'] ) . "', '" . mysqli_real_escape_string( $db_wordpress, $row['ip_address'] ) . "', '" . mysqli_real_escape_string( $db_wordpress, $row['date'] ) . "', '" . mysqli_real_escape_string( $db_wordpress, $row['date'] ) . "', '" . mysqli_real_escape_string( $db_wordpress, $row['content'] ) . "' )";
                increment_comment_count( strval( $int_post_id ), $comment_count );
                
                // Comments table insert query formation part.
                $index = 1;
                $row = mysqli_fetch_assoc( $res_select );
                while ( $row ) {
                    $int_post_id = intval( $row['post_id'] );
                    // Concatenate to the query string the next row to be inserted.
                    $output_query_string .= ',(' . $int_post_id . ", '" . mysqli_real_escape_string( $db_wordpress, $row['name'] ) . "', '" . mysqli_real_escape_string( $db_wordpress, $row['email'] ) . "', '" . mysqli_real_escape_string( $db_wordpress, $row['ip_address'] ) . "', '" . mysqli_real_escape_string( $db_wordpress, $row['date'] ) . "', '" . mysqli_real_escape_string( $db_wordpress, $row['date'] ) . "', '" . mysqli_real_escape_string( $db_wordpress, $row['content'] ) . "' )";
                    increment_comment_count( strval( $int_post_id ), $comment_count );
                    if ( $index % 100 == 0 ) {
                        // Reset the query string
                        $output_query_string .= ";\n" . 'INSERT INTO `' . $config_options['wordpress_tables_prefix'] . 'comments`(comment_post_ID, comment_author, comment_author_email, comment_author_IP, comment_date, comment_date_gmt, comment_content) VALUES ';
                        $row = mysqli_fetch_assoc( $res_select );
                        // First check that there still are values to insert, then concatenate to the query string the first row to be inserted.
                        if ( $row ) {
                            $int_post_id = intval( $row['post_id'] );
                            $output_query_string .= '(' . $int_post_id . ", '" . mysqli_real_escape_string( $db_wordpress, $row['name'] ) . "', '" . mysqli_real_escape_string( $db_wordpress, $row['email'] ) . "', '" . mysqli_real_escape_string( $db_wordpress, $row['ip_address'] ) . "', '" . mysqli_real_escape_string( $db_wordpress, $row['date'] ) . "', '" . mysqli_real_escape_string( $db_wordpress, $row['date'] ) . "', '" . mysqli_real_escape_string( $db_wordpress, $row['content'] ) . "' )";
                            increment_comment_count( strval( $int_post_id ), $comment_count );
                        }
                        $index = 1;
                    } else {
                        // Fetch a new row for insertion and increment the index counter.
                        $row = mysqli_fetch_assoc( $res_select );
                        $index ++;
                    }
                }
                // Just close the database connection.
                mysqli_close( $db_wordpress );
                // Posts table comment count update part.
                foreach ( $comment_count as $post_id => $count ) {
                    $output_query_string .= ";\nUPDATE `" . $config_options['wordpress_tables_prefix'] . "posts` SET comment_count = comment_count + $count WHERE ID = $post_id";
                }
                $output_query_string .= ';';

                // Write the query string to the output file.
                $file_handle = fopen( $config_options['path_to_output'], 'w' );
                fwrite( $file_handle, $output_query_string );
                fclose( $file_handle );
            } else {
                // Exit with error notice.
                exit( "FAILURE: Could not connect to the Wordpress database.\n" );       
            }
            // Close the connection to Vanilla db.
            mysqli_close( $db_vanilla );
        } else {
            // Exit with error notice.
            exit( "FAILURE: Could not run the select query on Vanilla database.\n" );   
        }
    } else {
        // Exit with error notice.
        exit( "FAILURE: Could not connect to the Vanilla database.\n" );
    }
    
    echo 'Successfully generated query. Stored at ' . $config_options['path_to_output'] . ".\n";
    // Exit successfully.
    exit( 0 );
} else {
    // Exit with error notice.
    exit( "FAILURE: Could not process config file.\n" );
}
?>