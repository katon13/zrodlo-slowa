<?php
namespace App\Core;

final class View
{
    public function __construct(private readonly string $basePath) {}

    public function render(string $view, array $data = [], string $layout = 'layouts/main'): string
    {
        $content = $this->partial($view, $data);
        return $this->partial($layout, array_merge($data, ['content' => $content]));
    }

    public function partial(string $view, array $data = []): string
    {
        $file = $this->basePath . '/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Brak widoku: {$view}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string)ob_get_clean();
    }
}
