<?php

namespace App\Listener;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpFoundation\Request;

class RequestListener
{
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $data = [];

        if ($request->getContentTypeFormat() === 'json') 
        {
            $data = json_decode($request->getContent(), true);
        } 
        elseif ($request->isMethod('POST') || $request->isMethod('PUT')) 
        {
            $data = $request->request->all();

            foreach ($request->files->all() as $key => $file) 
            {
                $data[$key] = $file;
            }
        }

        $data = is_array($data) ? $data : [];

        foreach($data as $key => &$value)
        {
            if($key[0] === '$')
            {
                unset($data[$key]);
                continue;
            }

            if ($value === '') 
            {
                $value = null;
            } 
            elseif ($value === 'true') 
            {
                $value = true;
            } 
            elseif ($value === 'false') 
            {
                $value = false;
            } 
            else if (is_numeric($value)) 
            {
                $value = $value + 0;
            }
            elseif (is_string($value) && ($decodedValue = json_decode($value, true)) !== null && json_last_error() === JSON_ERROR_NONE) 
            {
                if (is_array($decodedValue) || is_object($decodedValue)) 
                {
                    $value = $decodedValue;

                    foreach($value as &$val)
                    {
                        if(is_numeric($val))
                        {
                            $val = $val + 0;
                        }
                    }
                }
            }
        }

        $request->attributes->set('data', is_array($data) ? $data : []);

        $limit = (int) $request->query->get('limit', 100);
        $page = (int) $request->query->get('page', 1);
        $group = $request->query->get('group', 'month');
        $filters = json_decode(urldecode($request->query->get('filters', '[]')), true);

        $request->attributes->set('limit', max(1, min(100, $limit)));
        $request->attributes->set('page', max(1, min(100000, $page)));
        $request->attributes->set('group', $group);
        $request->attributes->set('filters', is_array($filters) ? $filters : []);
    }
}