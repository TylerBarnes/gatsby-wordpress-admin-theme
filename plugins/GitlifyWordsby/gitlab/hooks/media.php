<?php 

add_action('delete_attachment', 'deleteMedia');
function deleteMedia($id) {
    if (!defined('WORDSBY_GITLAB_PROJECT_ID')) return $id;

    $site_url = get_site_url();
    $current_user = wp_get_current_user()->data;
    $username = $current_user->user_nicename;

    $filepath = wp_get_attachment_metadata($id)['file'];
    $filename = basename($filepath);
    $filedirectory = dirname($filepath); 

    $base_path = 'wordsby/uploads';

    $fulldirectory = "$base_path/$filedirectory/";
    $full_filepath = "$fulldirectory$filename";

    $client = getGitlabClient();

    if (!$client) return;

    createMediaBranchIfItDoesntExist($client);
    
    global $mediaBranch;

    $media_exists = isFileInRepo(
        $client, $fulldirectory, $filename, $mediaBranch
    );

    if (!$media_exists) return;

    $actions = array();

    $edited_file_versions = getAllEditedFileVersionsInRepo(
        $client, $fulldirectory, $filename, $mediaBranch
    );

    if (count($edited_file_versions) > 0) {
        foreach($edited_file_versions as $file) {
            array_push($actions, [
                'action' => 'delete',
                'file_path' => $file['path']
            ]);
        }
    } else {
        array_push($actions, array(
            'action' => 'delete',
            'file_path' => $full_filepath
        ));
    }

    $commit = $client->api('repositories')->createCommit(
        WORDSBY_GITLAB_PROJECT_ID, 
        array(
            'branch' => $mediaBranch, 
            'commit_message' => "
                        \"$filename\" deleted 
                        — by $username (from $site_url)
            ",
            'actions' => $actions,
            'author_email' => $username,
            'author_name' => $current_user->user_email
        )
    );
}


add_filter('image_make_intermediate_size', 'commitEditedMedia');
function commitEditedMedia($full_filepath) {
    // bail out if this is an upload
    if (
        isset($_POST) && 
        isset($_POST['action']) && 
        $_POST['action'] !== 'image-editor'
        ) return $full_filepath;

    // bail out if this is an intermediate image size. 
    // gatsby creates our image sizes so we only commit full size images to the repo.
    if (
        !preg_match(
            '/^(?!.*-\d{2,4}x\d{2,4}).*\.(jpg|png|bmp|gif|ico)$/', $full_filepath
            )
        ) return $full_filepath;

    // bail if the file doesn't exist. It should, but just in case.
    if (!file_exists($full_filepath)) {
        jp_notices_add_error("There was an error saving your image. Please try again.");
        return $full_filepath;
    };

	$dirname = pathinfo( $full_filepath, PATHINFO_DIRNAME );
	$ext = pathinfo( $full_filepath, PATHINFO_EXTENSION );
    $filename = pathinfo( $full_filepath, PATHINFO_FILENAME );

    $repo_full_filepath = "wordsby/" . substr(
        $full_filepath, 
        strpos($full_filepath, "/uploads/") + 1
    );  

    $repo_filename = pathinfo( $repo_full_filepath, PATHINFO_BASENAME );
	$repo_dirname  = pathinfo( $repo_full_filepath, PATHINFO_DIRNAME );


    $client = getGitlabClient();
    if (!$client) return null; 

    createMediaBranchIfItDoesntExist($client);
    
    global $mediaBranch;


    $media_exists = isFileInRepo(
        $client, $repo_dirname, $repo_filename, $mediaBranch
    );
    $action = $media_exists ? 'update' : 'create';

    $original_filename = preg_replace( 
        '/-e([0-9]+)$/', '', $filename 
        ) . ".$ext";

    $repo_original_filepath = "$repo_dirname/$original_filename";
    
    $original_media_exists = isFileInRepo(
        $client, $repo_dirname, $original_filename, $mediaBranch
    );


    $site_url = get_site_url();
    $current_user = wp_get_current_user()->data;
    $username = $current_user->user_nicename;

    $commit_message = "
            \"$filename\" edited (\"$repo_filename\") 
            — by $username (from $site_url)
        ";

    $actions = array(
        array(
            'action' => $action,
            'file_path' => $repo_full_filepath,
            'content' => base64_encode(file_get_contents($full_filepath)),
            'encoding' => 'base64'
        )
    );

    // delete the original media file from the repo if 
    // IMAGE_EDIT_OVERWRITE is true.
    if (
        defined( 'IMAGE_EDIT_OVERWRITE' ) && 
        IMAGE_EDIT_OVERWRITE &&
        $original_media_exists
        ) {
        array_push($actions, array(
            'action' => 'delete',
            'file_path' => $repo_original_filepath,
        ));
    }

    $commit = $client->api('repositories')->createCommit(
        WORDSBY_GITLAB_PROJECT_ID, 
        array(
            'branch' => $mediaBranch, 
            'commit_message' => $commit_message,
            'actions' => $actions,
            'author_email' => $username,
            'author_name' => $current_user->user_email
        )
    );

    return $full_filepath;
}


add_action('wp_handle_upload', 'commitMedia');
function commitMedia($upload) {
    if (!defined("WORDSBY_GITLAB_PROJECT_ID")) return $upload;

    $initial_filepath = explode("uploads/", $upload['file'])[1];
    $filename = basename($initial_filepath);
    $subdir = dirname($initial_filepath);
    
    $base_path = 'wordsby/uploads';
    $file_dir = "$base_path/$subdir";
    $filepath = "$file_dir/$filename";

    $site_url = get_site_url();
    $current_user = wp_get_current_user()->data;
    $username = $current_user->user_nicename;

    $client = getGitlabClient();

    if (!$client) return;

    createMediaBranchIfItDoesntExist($client);

    global $mediaBranch;

    $media_exists = isFileInRepo($client, $file_dir, $filename, $mediaBranch);
    $action = $media_exists ? 'update' : 'create';

    $commit = $client->api('repositories')->createCommit(
        WORDSBY_GITLAB_PROJECT_ID, 
        array(
        'branch' => $mediaBranch, 
        'commit_message' => "
                    \"$filename\" 
                    — by $username (from $site_url)
        ",
        'actions' => array(
            array(
                'action' => $action,
                'file_path' => $filepath,
                'content' => base64_encode(file_get_contents($upload['file'])),
                'encoding' => 'base64'
            )
        ),
        'author_email' => $username,
        'author_name' => $current_user->user_email
    ));

    return $upload;
}


?>