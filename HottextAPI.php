<?php

use Silex\Application, Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request, Symfony\Component\HttpFoundation\Response;

class API implements ControllerProviderInterface {

  public static function convertAnnotations($post) {
    $federatedProfile = null;
    foreach ($post['user']['annotations'] as $a) {
      if ($a['type']=='net.lukasrosenstock.federatedprofile') {
        $federatedProfile = $a['value'];
        break;
      }
    }

    if ($federatedProfile) {
      $post['user']['canonical_url'] = $federatedProfile['profile_url'];
      $post['canonical_url'] = str_replace('{id}', $post['id'], $federatedProfile['post_url_template']);
    }

    return $post;
  }

  public function connect(Application $app) {
    $controllers = $app['controllers_factory'];

    $controllers->get('/topPosts', function() use ($app) {
      // Fetch posts from stream
      $streamResponse = $app['context']->getClient()
        ->get('/adn/posts/stream?count=200&include_user_annotations=1')
        ->send()->json();
      $posts = $streamResponse['data'];

      $topPosts = array();

      foreach ($posts as $p) {
        // Conditions:
        // - from a human
        // - no links - it's called hotTEXT for a reason
        // - not a directed post (no @ in the beginning)
        // - not a reply to another post
        if ($p['user']['type']!='human'
          || count($p['entities']['links'])>0
          || !isset($p['text'])
          || $p['text'][0]=='@'
          || isset($p['reply_to'])) continue;

        // Calculate value: replies * 20 + reposts * 15 + stars * 10
        // + 0-10 depending on follower count of creator
        // + 0-10 depending on followings count of creator
        // + 20 if user follows you
        $value = $p['num_replies']*20
          + $p['num_reposts']*15
          + $p['num_stars']*10
          + (($p['user']['counts']['following'] < 200) ? ($p['user']['counts']['following']*0.05) : 10)
          + (($p['user']['counts']['followers'] < 200) ? ($p['user']['counts']['followers']*0.05) : 10)
          + (@$p['user']['follows_you'] ? 20 : 0);

        // Must be above 20 to be considered
        if ($value<=20) continue;

        $p['hottextapp.value'] = $value;
        $topPosts[] = $p;
      }

      usort($topPosts, function($a, $b) {
        return $b['hottextapp.value']-$a['hottextapp.value'];
      });

      $postsFormatted = array();
      for ($i = 1; $i <= 5; $i++) {
        $p = API::convertAnnotations($topPosts[$i-1]);
        $dt = new \DateTime($p['created_at']);
        $postsFormatted[] = array(
          'username' => $p['user']['username'],
          'fullname' => $p['user']['name'],
          'user_url' => $p['user']['canonical_url'],
          'avatar_url' => $p['user']['avatar_image']['url'],
          'text' => $p['text'],
          'replies' => $p['num_replies'],
          'reposts' => $p['num_reposts'],
          'stars' => $p['num_stars'],
          'post_url' => $p['canonical_url'],
          'datetime' => $dt->format('Y-m-d H:i'),
          'datetime_formatted' => $dt->format('Y/m/d H:i'),
          'temp' => round(35+$p['hottextapp.value']*0.05, 1)."°C"
        );
      }

      return $app->json(array('posts' => $postsFormatted));
    });

    return $controllers;
  }
}
