<?php
namespace App\Helper;
use Illuminate\Support\Facades\Hash;
class Helper
{
    public static function apiResponse($result = null , $status = 200 , $additional = []) : \Illuminate\Http\JsonResponse
    {
        if ($status == 200) {
            $success = true;
            $Key = "data";
        }
        else {
            $success = false;
            $Key = "error";
        }
        if (is_string($result)) {
            $result = [
                "message" => $result ,
            ];
        }
        $data = [
            "status" => $status ,
            "success" => $success ,
            $Key => $result ,
        ];
        return response()->json(array_merge($data , $additional) , $status);
    }

    public static function HashedValue($value) : string
    {
        return  hash('sha256', $value);
    }
}
