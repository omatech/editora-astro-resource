<?php

namespace Omatech\AstroResource;

use function Lambdish\Phunctional\map;
use function Lambdish\Phunctional\reduce;

class AstroResource
{
    static public function routes()
    {
        return self::getInstanceRoutes()->reduce(function ($acc, $route) {
            $isDefaultLanguageRoute = $route->language === config('editora.defaultLanguage');
            $includeHomePrefix = config('editora.homeNiceUrl') === true;

            $rt['params']['slug'] = $route->niceurl;
            if (!$includeHomePrefix) {
                $link = str_replace('home', null, $route->niceurl);
                $rt['params']['slug'] = ($link === "") ? null : $link;
            }

            if (!$isDefaultLanguageRoute) {
                $rt['params']['locale'] = $route->language;
            }

            $acc[$isDefaultLanguageRoute ? 'default' : 'located'][] = $rt;
            return $acc;
        }, []);
    }

    static public function resources($instance, $global, $others = [])
    {
        return cache()->remember(
            'astro.resource.'.app()->getLocale().'.'.$instance['inst_id'],
            now()->addYear()->timestamp - now()->timestamp,
            function() use ($others, $instance, $global) {
                return response()->json(array_merge([
                    'language' => app()->getLocale(),
                    'global' => self::parseInstance($global),
                    'root' => self::parseInstance($instance),
                    'texts' => self::getStaticTexts()
                ], self::parseOthers($others)));
            });
    }

    static private function parseInstance($instance)
    {
        $instance = self::parseMeta($instance);
        return self::parseFields($instance);
    }

    static private function parseFields($instance)
    {
        return reduce(function ($acc, $value, $field) {
            if (!in_array($field, self::ignoreFieldsFromInstance()) && !self::isImage($field)) {
                if ($field === 'relations') {
                    $acc[$field] = self::parseRelations($value);
                } else if ($field === 'meta') {
                    $acc['meta'] = $value;
                } else {
                    $acc['fields'][$field] = $value;
                }
            }
            return $acc;
        }, $instance, []);
    }

    static private function parseRelations($relations)
    {
        return map(function ($relation) {
            return reduce(function ($acc, $relationInstances, $field) {
                $acc[$field] = self::parseInstance($relationInstances);
                return $acc;
            }, $relation['instances'] ?? [], []);
        }, $relations);
    }

    static private function parseOthers($others)
    {
        return map(function ($other) {
            return self::parseInstance($other);
        }, $others);
    }

    static private function isImage($field)
    {
        $pattern = '/\w+_(imgid|imghash|imgextension)/';
        return preg_match($pattern, $field) === 1;
    }

    static private function parseMeta($instance)
    {
        $instance['meta']['is_linkable'] = $instance['has_urlnice'] ?? false;
        $instance['meta']['link'] = ($instance['meta']['is_linkable']) ? $instance['link'] : null;
        $instance['meta']['link'] = self::parseLink($instance['meta']['link']);
        $instance['meta']['class'] = $instance['metadata']['class_name'] ?? null;
        $instance['meta']['alternative_links'] = self::getAlternativeLinks($instance);
        return $instance;
    }

    static private function getAlternativeLinks($instance)
    {
        return self::getInstanceRoutes()
            ->where('inst_id', $instance['inst_id'])
            ->reduce(function ($acc, $url) use ($instance) {
                $acc[$url->language] = self::parseLink('/' . $url->language . '/' . $url->niceurl);
                return $acc;
            }, []);
    }

    static private function parseLink($link)
    {
        $defaultLanguage = config('editora.defaultLanguage');
        $includeHomePrefix = config('editora.homeNiceUrl') === true;

        if (str_contains($link, '/' . $defaultLanguage . '/')) {
            $link = str_replace('/' . $defaultLanguage . '/', '/', $link);
        }

        if (str_contains($link, 'home') && !$includeHomePrefix) {
            $link = str_replace('/home', '/', $link);
            return strlen($link) > 1 ? rtrim($link, '/') : $link;
        }
        return $link;
    }

    static private function ignoreFieldsFromInstance()
    {
        return [
            'id', 'inst_id', 'lang', 'nom_intern', 'metadata', 'has_urlnice', 'niceurl', 'link'
        ];
    }

    static private function getInstanceRoutes()
    {
        return cache()->remember(
            'astro.routes',
            now()->addYear()->timestamp - now()->timestamp,
            function() {
                return OmpNiceurl::join('omp_instances', 'omp_instances.id', 'omp_niceurl.inst_id')
                    ->where('omp_instances.status', 'O')
                    ->limit(2000)
                    ->get();
            });
    }

    static private function getStaticTexts()
    {
        return cache()->remember(
            'astro.statictext.'.app()->getLocale(),
            now()->addYear()->timestamp - now()->timestamp,
            function() {
                return OmpStaticText::where('language', app()->getLocale())
                    ->get()
                    ->reduce(function ($acc, $text) {
                        $acc[$text->text_key] = $text->text_value;
                        return $acc;
                    }, []);
            }
        );
    }
}
