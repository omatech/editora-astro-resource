<?php

namespace Omatech\AstroResource;

use function Lambdish\Phunctional\map;
use function Lambdish\Phunctional\reduce;

class AstroResource
{
    public static function routes()
    {
        return self::getInstanceRoutes()->reduce(function ($acc, $route) {
            $isDefaultLanguageRoute = $route->language === config('editora.defaultLanguage');
            $includeHomePrefix = config('editora.homeNiceUrl') === true;

            $rt['params']['slug'] = (config('editora.astroRoutesPrefix')[$route->class_name] ?? null) . '/' . $route->niceurl;
            if (!$includeHomePrefix) {
                $link = str_replace('home', null, $rt['params']['slug']);
                $link = ltrim($link, '/');
                $rt['params']['slug'] = $link === '' ? null : $link;
            }

            if (!$isDefaultLanguageRoute) {
                $rt['params']['locale'] = $route->language;
            }

            $acc[$isDefaultLanguageRoute ? 'default' : 'located'][] = $rt;
            return $acc;
        }, []);
    }

    public static function resources($instance, $global, $others = [])
    {
        config('app.env') === 'local' ? cache()->forget('astro.resource.' . app()->getLocale() . '.' . $instance['inst_id']) : null;

        //SEO Transform
        $instance = self::seoSeparator($instance);
        unset($instance['relations']['SeoAbout']);
        unset($instance['relations']['SeoMention']);
        //END SEO Transform

        return cache()->remember(
            'astro.resource.' . app()->getLocale() . '.' . $instance['inst_id'],
            now()->addYear()->timestamp - now()->timestamp,
            function () use ($others, $instance, $global) {
                return response()->json(array_merge([
                    'language' => app()->getLocale(),
                    'global' => self::parseInstance($global),
                    'root' => self::parseInstance($instance),
                    'texts' => self::getStaticTexts()
                ], self::parseOthers($others)));
            }
        );
    }

    protected static function parseInstance($instance)
    {
        $instance = self::parseMeta($instance);
        return self::parseFields($instance);
    }

    protected static function parseFields($instance)
    {
        return reduce(function ($acc, $value, $field) {
            if (!in_array($field, self::ignoreFieldsFromInstance()) && !self::isImage($field)) {
                if ($field === 'relations') {
                    $acc[$field] = self::parseRelations($value);
                } else if ($field === 'meta') {
                    $acc['meta'] = $value;
                } else if ($field === 'seo') {
                    $acc['seo'] = $value;
                }
                else {
                    $acc['fields'][$field] = $value;
                }
            }
            return $acc;
        }, $instance, []);
    }

    protected static function parseRelations($relations)
    {
        return map(function ($relation) {
            return reduce(function ($acc, $relationInstances, $field) {
                $acc[$field] = self::parseInstance($relationInstances);
                return $acc;
            }, $relation['instances'] ?? [], []);
        }, $relations);
    }

    protected static function parseOthers($others)
    {
        return map(function ($other) {
            return self::parseInstance($other);
        }, $others);
    }

    protected static function isImage($field)
    {
        $pattern = '/\w+_(imgid|imghash|imgextension)/';
        return preg_match($pattern, $field) === 1;
    }

    protected static function parseMeta($instance)
    {
        $instance['meta']['is_linkable'] = $instance['has_urlnice'] ?? false;
        $instance['meta']['link'] = ($instance['meta']['is_linkable']) ? $instance['link'] : null;
        $instance['meta']['link'] = self::parseLink($instance['meta']['link']);
        $instance['meta']['class'] = $instance['metadata']['class_name'] ?? null;
        $instance['meta']['alternative_links'] = self::getAlternativeLinks($instance);
        return $instance;
    }

    protected static function getAlternativeLinks($instance)
    {
        if ($instance['meta']['is_linkable'] === false) {
            return [];
        }
        return self::getInstanceRoutes()
            ->where('inst_id', $instance['inst_id'])
            ->reduce(function ($acc, $url) {
                $acc[$url->language] = self::parseLink('/' . $url->language . '/' . $url->niceurl);
                return $acc;
            }, []);
    }

    protected static function parseLink($link)
    {
        $defaultLanguage = config('editora.defaultLanguage');
        $useDefaultLanguage = config('editora.astro.useDefaultLanguage');
        $includeHomePrefix = config('editora.homeNiceUrl') === true;

        if (str_contains($link, '/' . $defaultLanguage . '/') && !$useDefaultLanguage) {
            $link = str_replace('/' . $defaultLanguage . '/', '/', $link);
        }

        if (str_contains($link, 'home') && !$includeHomePrefix) {
            $link = str_replace('/home', '/', $link);
        }

        $link = rtrim($link, '/');
        return $link === '' ? '/' : $link;
    }

    protected static function ignoreFieldsFromInstance()
    {
        return [
            'id',
            'lang',
            'nom_intern',
            'metadata',
            'has_urlnice',
            'niceurl',
            'link',
            'meta_keywords',
            'meta_description',
            'og_description',
            'og_image',
            'og_type',
            'meta_title',
            'meta_robots',
            'og_title'
        ];
    }

    protected static function getInstanceRoutes()
    {
        config('app.env') === 'local' ? cache()->forget('astro.routes') : null;
        return cache()->remember(
            'astro.routes',
            now()->addYear()->timestamp - now()->timestamp,
            function () {
                $query = OmpNiceurl::join('omp_instances', 'omp_instances.id', 'omp_niceurl.inst_id')
                    ->join('omp_classes', 'omp_classes.id', 'omp_instances.class_id')
                    ->select('omp_niceurl.*', 'omp_classes.name as class_name')
                    ->where('omp_instances.status', 'O');
                if (config('editora.allowedLanguages', []) !== []) {
                    $query = $query->whereIn('omp_niceurl.language', config('editora.allowedLanguages', []));
                }
                return $query->limit(2000)->get();
            }
        );
    }

    protected static function getStaticTexts()
    {
        config('app.env') === 'local' ? cache()->forget('astro.statictext.' . app()->getLocale()) : null;
        return cache()->remember(
            'astro.statictext.' . app()->getLocale(),
            now()->addYear()->timestamp - now()->timestamp,
            function () {
                return OmpStaticText::where('language', app()->getLocale())
                    ->get()
                    ->reduce(function ($acc, $text) {
                        $acc[$text->text_key] = $text->text_value;
                        return $acc;
                    }, []);
            }
        );
    }

    private static function seoSeparator($fields)
    {
        if (!isset($fields['meta_robots'])) {
            return $fields;
        }

        $seo = [
            'meta_title' => $fields['meta_title'] ?? null,
            'meta_description' => $fields['meta_description'] ?? null,
            'meta_keywords' => $fields['meta_keywords'] ?? null,
            'meta_robots' => $fields['meta_robots'] ?? null,
            'og_title' => $fields['og_title'] ?? null,
            'og_description' => $fields['og_description'] ?? null,
            'og_image' => $fields['og_image'] ?? null,
            'og_type' => $fields['og_type'] ?? null,
        ];

        $nonSeo = $fields;
        foreach (array_keys($seo) as $key) {
            unset($nonSeo[$key]);
        }

        $result = $nonSeo;
        $result['seo'] = $seo;

        $ogImageTranform = self::ogImageTransform($result['seo']);
        $result['seo'] = $ogImageTranform;

        $result = self::ldJsonTransform($result);

        return $result;
    }

    private static function ldJsonTransform($fields)
    {
        $result = $fields;

        $link = preg_replace('#/home$#', '', $fields['link']);

        $ldJson = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            '@id' => $link . '#webpage', //Url unica de la pagina + #webpage
            'url' => $link, //URL unica de la pagina
            'inLanguage' => $fields['lang'], // Idioma de la pagina
            'name' => $fields['seo']['meta_title'], // meta-title
            'description' => $fields['seo']['meta_description'] //meta-description
        ];

        $result['seo']['ld'] = $ldJson;

        if (isset($fields['relations']['SeoAbout'])) {
            $about = self::seoAboutTranform($result);
            $result['seo'] = $about;
        }

        if (isset($fields['relations']['SeoMention'])) {
            $mention = self::seoMentionTransform($result);
            $result['seo'] = $mention;
        }

        return $result;
    }

    private static function seoAboutTranform($fields)
    {
        $about = [];

        if (isset($fields['relations']['SeoAbout']['instances'])) {
            foreach ($fields['relations']['SeoAbout']['instances'] as $item) {
                $name = $item['about_name'];
                $url = $item['about_url'];

                if ($name || $url) {
                    $about[] = [
                        "@id"  => $url,
                        "name" => $name
                    ];
                }
            }

            $fields['seo']['ld']['about'] = $about;
        }

        return $fields['seo'];
    }

    private static function seoMentionTransform($fields)
    {
        $mention = [];

        if (isset($fields['relations']['SeoMention']['instances'])) {
            foreach ($fields['relations']['SeoMention']['instances'] as $item) {
                $name = $item['mention_name'];
                $url = $item['mention_url'];

                if ($name || $url) {
                    $mention[] = [
                        "@id"  => $url,
                        "name" => $name
                    ];
                }
            }

            $fields['seo']['ld']['mention'] = $mention;
        }

        return $fields['seo'];
    }

    private static function ogImageTransform($fields)
    {
        if (empty($fields['og_image'])) {
            unset($fields['og_image']);
            return $fields;
        }

        $publicPath = public_path($fields['og_image']);

        $ogImageUrl = $fields['og_image'] ?? null;

        $ogImageType = null;
        $ogImageWidth = null;
        $ogImageHeight = null;
        if (file_exists($publicPath)) {
            $ogImageType = mime_content_type($publicPath) ?? null;

            [$width, $height] = getimagesize($publicPath) ?? null;
            $ogImageWidth = $width;
            $ogImageHeight = $height;
        }

        $fields['og_image_url'] = $ogImageUrl;
        $fields['og_image_type'] = $ogImageType;
        $fields['og_image_width'] = $ogImageWidth;
        $fields['og_image_height'] = $ogImageHeight;

        unset($fields['og_image']);

        return $fields;
    }
}
