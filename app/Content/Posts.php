<?php

namespace App\Content;

use Carbon\Carbon;
use Spatie\YamlFrontMatter\YamlFrontMatter;

class Posts extends Provider
{
    public function all()
    {
        $posts = $this->cache('posts.all', function () {
            return $this->gather();
        });

        return $posts->sortByDesc('updated')->each(function ($post) {
            $date = Carbon::parse($post->date);
            $post->dateShort = $date->format('j F Y');
        });
    }

    public function search($query)
    {
        return $this->all()->filter(function ($post) use ($query) {
            return stripos($post->title, $query) !== false
                || stripos($post->contents, $query) !== false
                || stripos($post->summary, $query) !== false
                || stripos($post->category, $query) !== false;
            // TDOD: tags & author
        });
    }

    public function notAPage()
    {
        return $this->all()
            ->filter(function ($post) {
                return $post->type != 'page';
            });
    }

    public function published()
    {
        return $this->all()
            ->filter(function ($post) {
                return $post->published;
            });
    }

    public function tag($name)
    {
        return $this->all()
            ->filter(function ($post) use ($name) {
                return in_array($name, $post->tags);
            });
    }

    public function category($name)
    {
        return $this->all()
            ->filter(function ($post) use ($name) {
                return $name == $post->category;
            });
    }

    public function first()
    {
        return $this->all()->first();
    }

    public function paginate($perPage = 15, $pageName = 'page', $page = null)
    {
        return $this->cache('posts.paginate.' . request('page', 1), function () use ($perPage, $pageName, $page) {
            return $this->all()
                ->simplePaginate($perPage, $pageName, $page);
        });
    }

    public function find($slug)
    {
        return tap($this->all()
            ->where('slug', $slug)
            ->first(), function ($post) use ($slug) {
            $key = $this->findKey($slug);

            $post->previous = $this->findPrevious($key);
            $post->next = $this->findNext($key);
        });
    }

    public function findKey($slug)
    {
        return $this->all()->search(function ($post) use ($slug) {
            return $post->slug == $slug;
        });
    }

    public function findPrevious($currentId)
    {
        return $this->notAPage()[$currentId - 1] ?? null;
    }

    public function findNext($currentId)
    {
        return $this->notAPage()[$currentId + 1] ?? null;
    }

    public function feed()
    {
        return $this->cache('posts.feed', function () {
            return $this->all()
                ->map(function ($post) {
                    return [
                        'id' => $post->url,
                        'title' => $post->title,
                        'updated' => $post->updated,
                        'summary' => $post->contents,
                        'link' => $post->url,
                        'author' => isset($post->author['name'])
                            ? $post->author['name']
                            : 'Origin Unknown',
                    ];
                });
        });
    }

    /**
     * Get all articles and parse them to objects
     *
     * @return static
     */
    private function gather()
    {
        return collect($this->disk->files('posts'))
            ->filter(function ($path) {
                return ends_with($path, '.md');
            })
            ->map(function ($path) {
                $filename = str_after($path, 'posts/');
                [$date, $slug, $extension] = explode('.', $filename, 3);
                $updated = Carbon::parse($date);
                $published = Carbon::parse($date)->toIso8601String();
                $date = Carbon::createFromFormat('Y-m-d', $date)->formatLocalized('%A, %d %B %Y');
                $document = YamlFrontMatter::parse($this->disk->get($path));

                return (object) [
                    'path' => $path,
                    'date' => $date,
                    'tags' => $document->tags ?? [],
                    'author' => config('me.authors')[$document->author ?? 'msingh'],
                    'slug' => $slug,
                    'objectID' => $slug,
                    'source' => $document->source ?? '',
                    'url' => route('post', [$slug]),
                    'external_url' => $document->external_url ?? false,
                    'title' => $document->title,
                    'category' => $document->category ?? 'what-the-fuck',
                    'category_formated' => ucwords(str_replace('-', ' ', $document->category ?? 'what-the-fuck')),
                    'contents' => markdown($document->body()),
                    'summary' => markdown($document->summary ?? $document->body()),
                    'summary_short_formated' => markdown(mb_strimwidth($document->summary ?? $document->body(), 0, 155, '...')),
                    'summary_short' => mb_strimwidth($document->summary ?? $document->body(), 0, 155, '...'),
                    'preview_image' => $document->preview_image
                        ? url($document->preview_image)
                        : url('/images/social.jpg'),
                    'published' => $document->published ?? $published,
                    'type' => $document->type,
                    'updated' => $updated,
                ];
            })
            ->sortByDesc(function ($post) {
                return carbon($post->date)->getTimestamp();
            });
    }
}
